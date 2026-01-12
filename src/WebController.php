<?php

namespace Flm;

use CachedEcho;
use Exception;
use SendFile;

// web controller
class WebController extends BaseController
{

    public function getConfig()
    {
        global $topDirectory;

        if (!empty($this->config['debug'])) {
            error_log("filemanager debug: topDirectory is " . (isset($topDirectory) ? "'$topDirectory'" : 'not set'));
        }

        // Use config root if set, else topDirectory, else default
        $rootDir = $this->config['root'] ?? $topDirectory ?? '/tmp';

        $archive = [];

        $settings = [
            'homedir' => rtrim($rootDir, DIRECTORY_SEPARATOR),
            'extensions' => $this->config['extensions'],
            'debug' => $this->config['debug'],
            'mkdefmask' => $this->config['mkdperm']
        ];

        foreach ($this->config['archive']['type'] as $ext => $conf) {
            $archive[$ext] = $conf;
        }

        $settings['archives'] = $archive;

        return $settings;
    }


    /**
     * @throws Exception
     */
    public function archiveCreate($params): array
    {
        isset($params->target) || self::jsonError(16);
        (isset($params->fls) && count($params->fls) > 0) || self::jsonError(22);
        isset($params->options) || self::jsonError(300);

        return $this->flm()->archiveCreate((array)$params->fls, $params->target, (array)$params->options);
    }

    /**
     * @throws Exception
     */
    public function archiveExtract($params): array
    {
        isset($params->to) || self::jsonError(2);
        (!isset($params->fls) || (count($params->fls) < 1)) && self::jsonError(22);

        return $this->flm()->archiveExtract((array)$params->fls, $params->to, (array)$params->options);
    }

    /**
     * @throws Exception
     */
    public function archiveList($params): array
    {
        isset($params->target) || self::jsonError(22);
        return $this->flm()->archiveList($params->target, $params->options);
    }

    public function newDirectory($params)
    {
        if (!isset($params->target)) {
            self::jsonError(16, $params->target);
        }

        return ['error' => !$this->flm()->newDir($params->target)];
    }

    public function fileDownload()
    {

        $data = $this->_getPostData(['target' => 16], false);

        $sf = $this->flm()->getFsPath($data['target']);
        
        error_log("filemanager fileDownload: target = " . $data['target'] . ", full path = $sf");

        // Custom download to avoid sendfile.php warnings
        if (file_exists($sf) && is_readable($sf)) {
            // Check if it's a file (not a directory)
            if (!is_file($sf)) {
                error_log("filemanager fileDownload: ERROR - target is not a file (is_dir=" . is_dir($sf) . ")");
                CachedEcho::send('log(theUILang.fErrMsg[6]+" - ' . $sf . ' / Cannot download directory");', "text/html");
                return;
            }
            
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($sf) . '"');
            header('Content-Length: ' . filesize($sf));
            readfile($sf);
            exit;
        } else {
            error_log("filemanager fileDownload: ERROR - file does not exist or not readable");
            CachedEcho::send('log(theUILang.fErrMsg[6]+" - ' . $sf . ' / "+theUILang.fErrMsg[3]);', "text/html");
        }
    }


    public function fileMediaInfo()
    {
        $data = $this->_getPostData(['target' => 16], false);
        $temp = $this->flm()->mediainfo((object)$data);

        return $temp;
    }

    public function fileRename($params)
    {

        if (!isset($params->to)) {
            self::jsonError(2);
        }

        if (!isset($params->target)) {
            self::jsonError(18);
        }

        $res = $this->flm()->rename((object)
        [
            'file' => $params->target,
            'to' => $params->to
        ]);

        return ['error' => !$res];
    }

    public function filesCopy($params)
    {

        if (!isset($params->fls) || (count($params->fls) < 1)) {
            self::jsonError(22);
        }

        if (!isset($params->to)) {
            self::jsonError(2);
        }

        $task = $this->flm()->copy($params);

        return $task;
    }

    public function filesMove($params)
    {

        if (!isset($params->fls) || (count($params->fls) < 1)) {
            self::jsonError(22);
        }

        if (!isset($params->to)) {
            self::jsonError(2);
        }

        $task = $this->flm()->move($params);

        return $task;
    }

    public function filesRemove($params)
    {
        if (!isset($params->fls) || (count($params->fls) < 1)) {
            self::jsonError(22);
        }

        $task = $this->flm()->remove($params);

        return $task;
    }

    public function checkPostTargetAndDestination()
    {

        return $this->_getPostData(['target' => 18, 'to' => 18], false);
    }

    public function checkPostSourcesAndDestination()
    {

        return $this->_getPostData(['fls' => 22, 'to' => 2], false);
    }

    public function checksumVerify($params)
    {

        if (!isset($params->target)) {
            self::jsonError(2);
        }

        $task = $this->flm()->checksumVerify($params);

        return $task;
    }

    public function checksumCreate($params)
    {

        if (!isset($params->fls) || (count($params->fls) < 1)) {
            self::jsonError(22);
        }
        if (!isset($params->target)) {
            self::jsonError(2);
        }

        $task = $this->flm()->checksumCreate($params);

        return $task;
    }

    public function sess()
    {
        // $e->get_session();
    }

    public function listDirectory($params)
    {
        $contents = $this->flm()->dirlist($params);

        // Use string arithmetic to avoid 32-bit integer overflow for files >2GB.
        // Keep result as a decimal string to prevent JSON exponent notation.
        $dirSize = '0';
        foreach ($contents as $entry) {
            if (!isset($entry['size'])) {
                continue;
            }
            $size = self::normalizeUnsignedSizeString($entry['size']);
            if ($size === '') {
                continue;
            }
            $dirSize = self::addUnsignedDecimalStrings($dirSize, $size);
        }

        return ['listing' => $contents, 'dirSize' => $dirSize];
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

            // Common case: already a decimal integer string.
            if (preg_match('/^\d+$/', $value)) {
                return ltrim($value, '0') !== '' ? ltrim($value, '0') : '0';
            }

            // If a numeric string slipped in (e.g. float), format it safely.
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

    private static function addUnsignedDecimalStrings(string $a, string $b): string
    {
        // Both inputs must be non-negative decimal strings.
        if ($a === '') {
            $a = '0';
        }
        if ($b === '') {
            $b = '0';
        }

        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '') {
            $a = '0';
        }
        if ($b === '') {
            $b = '0';
        }

        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $out = '';

        while ($i >= 0 || $j >= 0 || $carry) {
            $da = ($i >= 0) ? (ord($a[$i]) - 48) : 0;
            $db = ($j >= 0) ? (ord($b[$j]) - 48) : 0;
            $sum = $da + $db + $carry;
            $out = chr(48 + ($sum % 10)) . $out;
            $carry = intdiv($sum, 10);
            $i--;
            $j--;
        }

        $out = ltrim($out, '0');
        return $out !== '' ? $out : '0';
    }

    public function viewNfo($params)
    {
        if (!isset($params->target)) {
            self::jsonError(2);
        }

        $contents = $this->flm()->nfo_get($params->target);

        return ['error' => 0, 'nfo' => $contents];
    }

    /**
     * Stream NFO/text file directly as plain text (no JSON wrapper).
     * This avoids large JSON encoding/escaping overhead and can be much faster
     * on low-powered devices.
     */
    public function viewNfoRaw($params)
    {
        if (!isset($params->target)) {
            self::jsonError(2);
        }

        // Allow long-running reads for large files
        @set_time_limit(300);

        // Clear any existing output buffers
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $contents = $this->flm()->nfo_get($params->target);
        echo $contents;
        exit;
    }

    /**
     * Serve image file inline for viewing
     */
    public function viewImage($params)
    {
        if (!isset($params->target) || $params->target === null || $params->target === '') {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Content-Type: text/plain; charset=utf-8');
            header('HTTP/1.1 400 Bad Request');
            echo 'Missing target';
            exit;
        }

        $file = $this->flm()->getFsPath($params->target);
        if (!is_file($file)) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Content-Type: text/plain; charset=utf-8');
            header('HTTP/1.1 404 Not Found');
            echo 'File not found';
            exit;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml'
        ];

        if (!isset($mimeTypes[$ext])) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Content-Type: text/plain; charset=utf-8');
            header('HTTP/1.1 415 Unsupported Media Type');
            echo 'Not an image file';
            exit;
        }

        // Clear any existing output buffers
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: ' . $mimeTypes[$ext]);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($file);
        exit;
    }

}