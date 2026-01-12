<?php

namespace Flm;

use Exception;
use FileUtil;
use rTask;

class Filesystem
{
    protected $root;
    protected $name;

    private static function permBitsToLsString(int $permBits, string $typeChar): string
    {
        // Normalize first char like `ls -l`.
        $first = match ($typeChar) {
            'd' => 'd',
            'l' => 'l',
            'b' => 'b',
            'c' => 'c',
            'p' => 'p',
            's' => 's',
            default => '-',
        };

        $out = $first;
        $out .= ($permBits & 0400) ? 'r' : '-';
        $out .= ($permBits & 0200) ? 'w' : '-';
        $out .= ($permBits & 0100) ? 'x' : '-';
        $out .= ($permBits & 0040) ? 'r' : '-';
        $out .= ($permBits & 0020) ? 'w' : '-';
        $out .= ($permBits & 0010) ? 'x' : '-';
        $out .= ($permBits & 0004) ? 'r' : '-';
        $out .= ($permBits & 0002) ? 'w' : '-';
        $out .= ($permBits & 0001) ? 'x' : '-';

        // Apply suid/sgid/sticky overlays
        if ($permBits & 04000) {
            $out[3] = (($permBits & 0100) ? 's' : 'S');
        }
        if ($permBits & 02000) {
            $out[6] = (($permBits & 0010) ? 's' : 'S');
        }
        if ($permBits & 01000) {
            $out[9] = (($permBits & 0001) ? 't' : 'T');
        }

        return $out;
    }

    private static function uidToName(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            if (is_array($pw) && isset($pw['name']) && $pw['name'] !== '') {
                return (string)$pw['name'];
            }
        }
        return (string)$uid;
    }

    private static function gidToName(int $gid): string
    {
        if (function_exists('posix_getgrgid')) {
            $gr = @posix_getgrgid($gid);
            if (is_array($gr) && isset($gr['name']) && $gr['name'] !== '') {
                return (string)$gr['name'];
            }
        }
        return (string)$gid;
    }

    private static function normalizeUnsignedSizeString($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            if ($value >= 0) {
                return (string)$value;
            }
            // Best-effort: interpret negative 32-bit overflow as unsigned.
            return sprintf('%u', $value);
        }

        if (is_float($value)) {
            if ($value < 0) {
                $asInt = (int)$value;
                return ($asInt < 0) ? sprintf('%u', $asInt) : (string)$asInt;
            }
            return sprintf('%.0f', $value);
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            if (preg_match('/^\d+$/', $value)) {
                $v = ltrim($value, '0');
                return $v !== '' ? $v : '0';
            }
            if (is_numeric($value)) {
                $f = (float)$value;
                if ($f < 0) {
                    $asInt = (int)$f;
                    return ($asInt < 0) ? sprintf('%u', $asInt) : (string)$asInt;
                }
                return sprintf('%.0f', $f);
            }
        }

        return '';
    }

    /**
     * Filesystem constructor.
     * @param string $root
     */
    public function __construct($root)
    {
        $this->root = FileUtil::addslash($root);
    }

    /**
     * @param string $target
     * @param bool $recursive
     * @param null $mode
     * @return bool
     * @throws Exception
     */
    public function mkdir($target, $recursive = false, $mode = null)
    {
        if (self::isDir($target)) {
            throw new Exception($target, 16);
        }
        $res = ShellCmds::mkdir($this->rootPath($target), $recursive, $mode)->run();

        if ($res[0] > 0) {
            throw new Exception("Error Processing Request: " . $target, 4);
        }

        return true;
    }

    public function isDir($path): bool
    {
        $path = $this->rootPath($path);
        return RemoteShell::test($path, 'd');
    }

    public function rootPath($relative_path = null): string
    {
        $debug = (bool)Helper::getConfig('debug');
        $debug && error_log("filesystem rootPath: relative_path = " . var_export($relative_path, true) . ", root = " . var_export($this->root, true));
        
        if ($relative_path == null) {
            $debug && error_log("filesystem rootPath: returning root (null case)");
            return $this->root;
        }
        
        // If the path already starts with root, return it as-is (avoid double concatenation)
        $root_trimmed = rtrim($this->root, '/');
        if (strpos($relative_path, $root_trimmed . '/') === 0 || rtrim($relative_path, '/') === $root_trimmed) {
            $debug && error_log("filesystem rootPath: path already contains root, returning as-is");
            return $relative_path;
        }
        
        // If the path is already the root, return it as-is
        $relative_trimmed = rtrim($relative_path, '/');
        $debug && error_log("filesystem rootPath: comparing '$relative_trimmed' === '$root_trimmed'");
        
        if ($relative_trimmed === $root_trimmed) {
            $debug && error_log("filesystem rootPath: paths match, returning root");
            return $this->root;
        }

        $result = FileUtil::fullpath(ltrim($relative_path, '/'), $this->root);
        $debug && error_log("filesystem rootPath: FileUtil::fullpath result = $result");
        return $result;
    }

    public function pathExists($path)
    {
        $path = $this->rootPath($path);
        return RemoteShell::test($path, 'e');
    }

    public function isFile($path)
    {
        $path = $this->rootPath($path);
        return RemoteShell::test($path, 'f');
    }

    /**
     * @throws Exception
     */
    public function copy($files, $dest): array
    {
        $to = $this->rootPath($dest);

        $commands = ['echo ' . Helper::mb_escapeshellarg('-> ' . $dest)];
        foreach ($files as $file) {
            $commands[] = "printf '%s' " . Helper::mb_escapeshellarg(basename($file) . " ... ");
            $commands[] = ShellCmds::recursiveCopy($this->rootPath($file), $to)
                ->cmd();

            $commands =
                array_merge($commands,
                    ["{", 'echo ' . Helper::mb_escapeshellarg("✔"), '}'],
                    ['!{', 'echo ' . Helper::mb_escapeshellarg("✖"), '}']
                );
        }

        $rtask = TaskController::task([
            'name' => 'copy',
            'arg' => count($files) . ' files'
        ]);

        return $rtask->start($commands, rTask::FLG_DEFAULT ^ rTask::FLG_ECHO_CMD);
    }

    /**
     * @throws Exception
     */
    public function move($files, $to): array
    {
        $commands = [];
        $commands = ['echo ' . Helper::mb_escapeshellarg('-> ' . $to)];

        $to = $this->rootPath($to);

        foreach ($files as $file) {
            $file = $this->rootPath($file);
            $commands[] = "printf '%s' " . Helper::mb_escapeshellarg(basename($file) . " ... ");
            $commands[] = ShellCmds::recursiveMove($file, $to)->cmd();

            $commands =
                array_merge($commands,
                    ["{", 'echo ' . Helper::mb_escapeshellarg("✔"), '}'],
                    ['!{', 'echo ' . Helper::mb_escapeshellarg("✖"), '}']
                );

            // ->end('&& echo')->addArgs(['✓ ' . basename($file)])
        }

        $rtask = TaskController::task([
            'name' => 'move',
            'arg' => count($files) . ' files',
            'files' => $files
        ]);

        $ret = $rtask->start($commands, rTask::FLG_DEFAULT ^ rTask::FLG_ECHO_CMD);

        return $ret;
    }

    public function remove($files): array
    {
        $commands = [];

        foreach ($files as $file) {
            $commands[] = ShellCmds::recursiveRemove($this->rootPath($file))->cmd();
        }

        $rtask = TaskController::task([
            'name' => 'remove',
            'arg' => count($files) . ' files',
            'files' => $files
        ]);

        $ret = $rtask->start($commands, rTask::FLG_DEFAULT ^ rTask::FLG_ECHO_CMD);

        return $ret;
    }

    /**
     * @param $from
     * @param $to
     * @return array
     * @throws Exception
     */
    public function rename($from, $to): bool
    {
        $res = ShellCmds::recursiveMove($this->rootPath($from), $this->rootPath($to))
            ->run();

        if ($res[0] > 0) {
            throw new Exception(implode("\n", $res[1]), 4);
        }

        return true;
    }

    /**
     * @param $directory_path
     * @return array
     * @throws Exception
     */
    public function listDir($directory_path)
    {
        error_log("filesystem listDir START: directory_path input = $directory_path");
        $directory_path = $this->rootPath($directory_path);
        error_log("filesystem listDir: after rootPath = $directory_path");
        
        // Check if directory exists and is readable
        error_log("filesystem listDir: checking is_dir...");
        $is_dir_result = is_dir($directory_path);
        error_log("filesystem listDir: is_dir result = " . ($is_dir_result ? 'true' : 'false'));
        
        if (!$is_dir_result) {
            error_log("filesystem listDir: ERROR - directory does not exist: $directory_path");
            throw new \Exception("Directory does not exist: $directory_path", 1);
        }
        
        error_log("filesystem listDir: checking is_readable...");
        $is_readable_result = is_readable($directory_path);
        error_log("filesystem listDir: is_readable result = " . ($is_readable_result ? 'true' : 'false'));
        
        if (!$is_readable_result) {
            error_log("filesystem listDir: ERROR - directory not readable: $directory_path");
            throw new \Exception("Directory not readable: $directory_path", 1);
        }
        
        error_log("filesystem listDir: using native PHP scandir...");
        // Use native PHP functions for local filesystem
        $files = @scandir($directory_path);
        if ($files === false) {
            error_log("filesystem listDir: ERROR - scandir failed");
            throw new \Exception("Failed to list directory: " . $directory_path, 1);
        }
        
        error_log("filesystem listDir: processing " . count($files) . " files...");
        $output = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $fullpath = rtrim($directory_path, '/') . '/' . $file;
            $stat = @stat($fullpath);
            if ($stat === false) continue;
            
            $is_directory = is_dir($fullpath);
            $type = $is_directory ? 'd' : (is_link($fullpath) ? 'l' : 'f');
            
            // Add trailing slash to directory names for frontend detection
            $display_name = $is_directory ? $file . '/' : $file;
            $size = $is_directory ? '' : self::normalizeUnsignedSizeString($stat['size']);
            $mtime = $stat['mtime'];
            $permBits = (int)($stat['mode'] & 07777);
            $perm = self::permBitsToLsString($permBits, $type);
            $user = self::uidToName((int)$stat['uid']);
            $group = self::gidToName((int)$stat['gid']);
            
            $output[] = [
                'type' => $type,
                'name' => $display_name,
                'size' => $size,
                // ruTorrent filemanager JS expects epoch seconds in `time`
                'time' => $mtime,
                // keep `date` for compatibility with older code paths
                'date' => $mtime,
                'perm' => $perm,
                'user' => $user,
                'group' => $group,
            ];
        }
        
        error_log("filesystem listDir: returning " . count($output) . " items");
        return $output;
    }


    public static function parseFileListing($contents, $pattern): array
    {
        $output = [];
        foreach ($contents as $fileline) {
            if (!empty($fileline)) {
                if (preg_match($pattern, $fileline, $matches)) {
                    $f = [
                        'type' => strtolower(trim($matches['type'])),
                        'name' => stripslashes($matches['name']),
                        'size' => trim($matches['size']),
                        'time' => trim($matches['date']),
                        'perm' => trim($matches['perm']),
                    ];

                    if ($f['type'] == 'd' && substr($f['name'], 0, 1) !== DIRECTORY_SEPARATOR) {
                        $f['name'] .= DIRECTORY_SEPARATOR;
                        $f['size'] = '';
                    }

                    $output[] = $f;
                }
//                else {
//                    var_dump(__METHOD__, 'not matched', $fileline);
//                }
            }

        }

        return $output;
    }
}
