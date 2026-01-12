<?php

namespace Flm;

use Exception;
use FileUtil;
use rTask;
use rTorrentSettings;
use Utility;

class FileManager
{
    public $workdir = '';

    /**
     * @var Filesystem
     */
    protected $fs;
    protected $temp = [];

    protected $settings = [];

    public function __construct($fileSystem, $config, $currentDir = null)
    {
        $this->fs = $fileSystem;
        $this->workDir($currentDir != null ? $currentDir : '/');
        $this->settings = $config;
    }

    public function workDir($directory = null)
    {
        if ($directory != null) {
            $this->workdir = FileUtil::addslash($directory);
        }
        /*
            $path_check = explode($this->rootDir, FileUtil::addslash(FileUtil::fullpath($dir, $this->rootDir)));
            if (count($path_check) < 2) {
                $dir = $this->rootDir;
            }
        */

        return $this->workdir;
    }

    static public function dir_sort($a, $b)
    {
        $a_isdir = ($a['type'] == 'd');

        $b_isdir = ($b['type'] == 'd');

        if ($a_isdir && $b_isdir) {
            return strcmp($a['name'], $b['name']);
        } elseif ($a_isdir) {
            return -1;
        } elseif ($b_isdir) {
            return 1;
        }

        return strcmp($a['name'], $b['name']);
    }

    public function extractChrootPath($fullPath)
    {
        $f = explode($this->fs->rootPath(), $fullPath);

        $relative = $fullPath;

        if (count($f) > 1) {
            $relative = $f[1];
        }

        return '/' . rtrim($relative, '/');
    }

    public function fs()
    {
        return $this->fs;
    }

    /**
     * @param string $archive_path
     * @param array $files
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function archiveCreate(array $files, string $archive_path, array $options = []): array
    {
        $archive_path = Helper::makeRelative($this->currentDir($archive_path));
        $archive_file = array_shift($archive_path);

        $config = Helper::getConfig('archive');

        if (!isset($config['type'][$options['type']])) {
            throw new Exception("Invalid type: " . $options['type'], 1);
        }

        $files = Helper::makeRelative((array)$files);

        if ($this->fs->isFile($archive_file)) {
            throw new Exception($archive_file, 16);
        }

        return Archive::from($archive_file, $options, $config)
            ->setWorkDir(FileUtil::addslash($this->getFsPath()))
            ->create($files);
    }

    public function currentDir($relative_path = null)
    {
        $debug = !empty($this->settings['debug']);
        
        if ($relative_path == null) {
            return $this->workdir;
        }
        
        // If relative_path is just '/', return workdir (don't append)
        if (trim($relative_path, '/') === '') {
            return $this->workdir;
        }

        $relative_path = (string)$relative_path;

        // If the path is already an absolute filesystem path under workdir, keep it.
        // This prevents double-prepending workdir when calling getFsPath(currentDir(...)).
        if (strpos($relative_path, $this->workdir) === 0) {
            return $relative_path;
        }

        // Treat jail-style paths like "/downloads/..." as relative to workdir.
        return FileUtil::fullpath(ltrim($relative_path, '/'), $this->workdir);
    }

    public function getFsPath($relative = null)
    {
        return $this->fs->rootPath($this->currentDir($relative));
    }

    public function archiveExtract(array $files, string $to, array $options = []): array
    {
        $to = $this->currentDir($to);

        if ($this->fs->isFile($to)) {
            throw new Exception($to, 16);
        } else if (!RemoteShell::test($this->getFsPath($to), 'w')) {
            throw new Exception("Not writable: " . $to, 300);
        }
        $count = count($files);
        $cmds = [];
        foreach ($files as $archive_file) {
            $archive_file = $this->currentDir($archive_file);
            if (!$this->fs->isFile($archive_file)) {
                throw new Exception($archive_file, 6);
            }

            $cmds = array_merge($cmds, Archive::from($this->getFsPath($archive_file), $options)
                ->extract($this->getFsPath($to))
            );
        }

        $rtask = TaskController::from([
            'name' => 'unpack',
            'arg' => $count == 1 ? basename($files[0]) : $count . ' items'
        ]);

        return $rtask->start($cmds, rTask::FLG_DEFAULT & ~rTask::FLG_ECHO_CMD);
    }

    /**
     * @throws Exception
     */
    public function archiveList(string $archive_file, $options): array
    {
        $archive_file = $this->currentDir($archive_file);
        if (!$this->fs->isFile($archive_file)) {
            throw new Exception($archive_file, 6);
        }

        $opts = is_object($options) ? get_object_vars($options) : (array)$options;

        $path = $opts['path'] ?? null;
        $archive_file = $this->getFsPath($archive_file);
        $in_background = !empty($opts['background']);
        $conf = Helper::getConfig('archive');

        if($in_background)
        {
            $conf['list_limit'] = 0;
        }
        $cmd = Archive::from($archive_file, (object)$opts, $conf)
            ->list($path);

        if($in_background) {
            $task =  TaskController::from([
                'name' => 'archive-list',
                'arg' => basename($archive_file)
            ])->start([$cmd->cmd()], rTask::FLG_DEFAULT &~ rTask::FLG_ECHO_CMD);

            ($task['finish'] > 0) && rTask::clean(rTask::formatPath($task['no']));

            $listing = $task;
        } else {
            $res = $cmd->run();
            $exitCode = $res[0];
            $lines = $res[1];

            // If 7z fails, don't pretend the archive is empty.
            if ($exitCode !== 0) {
                $msg = is_array($lines) ? trim(implode("\n", $lines)) : (string)$lines;
                throw new Exception($msg !== '' ? $msg : 'Archive listing failed', 300);
            }

            $files = [];
            $directories = [];
            $currentPath = null;
            $isFolder = false;
            $inTableSection = false;

            foreach ((array)$lines as $line) {
                $line = rtrim((string)$line, "\r\n");

                // Format -slt: "Path = filename"
                if (preg_match('/^Path = (.*)$/', $line, $m)) {
                    if (is_string($currentPath) && $currentPath !== '' && $currentPath !== '.') {
                        if ($isFolder) {
                            $directories[] = $currentPath;
                        } else {
                            $files[] = $currentPath;
                        }
                    }
                    $currentPath = $m[1];
                    $isFolder = false;
                    continue;
                }

                // Format -slt: "Folder = +"
                if (is_string($currentPath) && preg_match('/^Folder = ([+-])$/', $line, $m)) {
                    $isFolder = ($m[1] === '+');
                    continue;
                }

                // Table format: detect separator line
                if (preg_match('/^-+\s+-+\s+-+/', $line)) {
                    $inTableSection = !$inTableSection;
                    continue;
                }

                // Table format: parse file lines (YYYY-MM-DD HH:MM:SS ....)
                if ($inTableSection && preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', $line)) {
                    // Parse: Date Time Attr Size Compressed Name (with flexible spacing and any attr characters)
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})\s+(\S{5})\s+(\d+)\s+(\d+)\s+(.+)$/', $line, $m)) {
                        $filename = trim($m[6]);
                        $attr = $m[3];
                        
                        if (!empty($filename) && $filename !== '.' && $filename !== '..') {
                            $fileInfo = [
                                'name' => $filename,
                                'date' => $m[1],
                                'time' => $m[2],
                                'attr' => $attr,
                                'size' => (int)$m[4],
                                'compressed' => (int)$m[5]
                            ];
                            
                            // Check if it's a directory (attribute starts with 'D')
                            if (strpos($attr, 'D') === 0) {
                                $directories[] = $fileInfo;
                            } else {
                                $files[] = $fileInfo;
                            }
                        }
                    }
                    continue;
                }
            }

            // Handle last entry from -slt format
            // Skip if we already have table format data (indicated by structured array entries)
            if (is_string($currentPath) && $currentPath !== '' && $currentPath !== '.' && count($files) === 0) {
                if ($isFolder) {
                    $directories[] = $currentPath;
                } else {
                    $files[] = $currentPath;
                }
            }

            // Stable, structured response for UI.
            $listing = [
                'files' => array_values($files),
                'directories' => array_values($directories),
                'format' => '7z-table',
                'raw_output' => implode("\n", $lines)  // Add raw output for display
            ];
        }

        //$contents = Filesystem::parseFileListing($listing[1], Archive::LIST_FORMAT);
        //usort($contents, [$this, 'dir_sort']);

        return $listing;
    }

    /**
     * @param $paths
     * @return array
     * @throws Exception
     */
    public function copy($paths): array
    {
        $files = array_map([$this, 'currentDir'], (array)$paths->fls);
        $to = $this->currentDir($paths->to);

        if (count($files) > 1 && !$this->fs->isDir($to)) {
            throw new Exception("Destination is not directory", 2);
        } elseif (count($files) == 1 && $this->fs->isFile($to)) {
            throw new Exception("Destination already exists", 2);
        } elseif (count($files) > 1) {
            // to must be a directory
            $to = FileUtil::addslash($to);
        }

        $task_info = $this->fs->copy($files, $to);

        return $task_info;
    }

    /**
     * @param $paths
     * @return array
     * @throws Exception
     */
    public function dirlist($paths)
    {
        $dirpath = $this->currentDir($paths->dir);
        $directory_contents = $this->fs->listDir($dirpath);

        usort($directory_contents, [$this, 'dir_sort']);

        // Don't remove 'type' - frontend needs it to distinguish files from directories
        // foreach ($directory_contents as $key => $value) {
        //     unset($directory_contents[$key]['type']);
        // }

        return $directory_contents;
    }

    /**
     * @param array $files
     * @param string $to
     * @param array $options
     * @return array
     * @throws Exception
     */

    public function mediainfo($path)
    {
        $file = $this->currentDir($path->target);

        if (!$this->fs->isFile($file)) {
            throw new Exception($file, 6);
        }

        $commands = [];
        $flags = '';
        $st = mediainfoSettings::load();
        $task = new rTask([
            'arg' => basename($file),
            'requester' => 'filemanager',
            'name' => 'mediainfo',
            'no' => 0
        ]);
        if ($st && !empty($st->data["mediainfousetemplate"])) {
            $randName = $task->makeDirectory() . "/opts";
            file_put_contents($randName, $st->data["mediainfotemplate"]);
            $flags = "--Inform=file://" . escapeshellarg($randName);
        }
        $commands[] = Utility::getExternal("mediainfo") . " " . $flags . " " . Helper::mb_escapeshellarg($this->getFsPath($path->target));
        $ret = $task->start($commands, rTask::FLG_WAIT);

        return $ret;
    }

    /**
     * @param $paths
     * @return array
     * @throws Exception
     */
    public function move($paths)
    {
        $files = array_map([$this, 'currentDir'], (array)$paths->fls);

        // destination is dir ending in /
        $to = FileUtil::addslash($this->currentDir($paths->to));

        if (!$this->fs->isDir($to)) {
            throw new Exception("Destination is not directory: " . $paths->to, 2);
        }

        $task_info = $this->fs->move($files, $to);

        return $task_info;
    }


    /**
     * @param $dirpath
     * @return bool
     * @throws Exception
     */
    public function newDir($dirpath)
    {
        return $this->fs->mkdir($this->currentDir($dirpath), true, Helper::getConfig('mkdperm'));
    }

    /**
     * @param $file
     * @param bool $dos
     * @return mixed|string|string[]|null
     * @throws Exception
     */
    public function nfo_get($file)
    {
        $fullpath = $this->getFsPath($file);

        if (!is_file($fullpath)) {
            throw new Exception($file, 6);
        }
        
        $filesize = filesize($fullpath);
        
        // Allow all files up to 100MB (ACE Editor will handle text/binary safely with UTF-8 sanitization)
        if ($filesize > 104857600) {
            throw new Exception($file, 18);
        }

        $nfo = new NfoView($fullpath);

        return $nfo->get();
    }

    /**
     * @param $paths
     * @return array
     * @throws Exception
     */
    public function rename($paths): array
    {
        $file = $this->currentDir($paths->file);
        $to = $this->currentDir($paths->to);

        if (!$this->fs->pathExists($file)) {
            throw new Exception($file, 6);
        }
        if ($this->fs->pathExists($to)) {
            throw new Exception($to, 16);
        }

        $res = $this->fs->rename($file, $to);

        if ($res) {
            rTorrentSettings::get()->pushEvent('File_rename', [$file, $to]);
        }

        return ['success' => $res];
    }

    public function remove($paths): array
    {
        $files = array_map([$this, 'currentDir'], (array)$paths->fls);
        $task_info = $this->fs->remove($files);

        return $task_info;
    }

    /**
     * @param $paths
     * @return array|mixed
     * @throws Exception
     */
    public function checksumVerify($paths)
    {
        $hashesFile = $this->currentDir($paths->target);

        if (!isset(Helper::getConfig('extensions')['checksum'][$paths->type])) {
            throw new Exception($hashesFile, 18);
        }

        $hashType = $paths->type;

        if (!$this->fs->isFile($hashesFile)) {
            throw new Exception($hashesFile, 6);
        }

        $hashesFile = $this->getFsPath($hashesFile);

        $task_opts = [
            'name' => 'checksum-verify',
            'arg' => $this->currentDir($paths->target)
        ];

        $rtask = TaskController::from($task_opts);
        $commands = [TaskController::getTaskCmd(FileChecksum::class . '::fromChecksumFile', [$hashesFile, $hashType])];

        $ret = $rtask->start($commands, 0);

        return $ret;
    }

    /**
     * @param $paths
     * @return array|mixed
     * @throws Exception
     */
    public function checksumCreate($paths)
    {

        $checksums_file = $this->currentDir($paths->target);
        $files = array_map([$this, 'getFsPath'], (array)$paths->fls);

        if ($this->fs->isFile($checksums_file)) {
            throw new Exception($checksums_file, 16);
        }

        if (empty($files)) {
            throw new Exception("File list is empty");
        }

        $type = $paths->type ?? 'CRC32';

        $task_opts = [
            'name' => 'checksum-create',
            'arg' => $type . ' ' . $checksums_file
        ];

        $rtask = TaskController::from($task_opts);

        $filelist = ($rtask->writeFile)("files.json", json_encode($files));

        $commands = [TaskController::getTaskCmd(FileChecksum::class . '::checksumFromFilelist',
            [$filelist, $this->getFsPath($checksums_file), $type])];

        $ret = $rtask->start($commands, rTask::FLG_DEFAULT ^ rTask::FLG_ECHO_CMD);

        return $ret;
    }
}
