<?php

namespace Flm;

use Exception;

class NfoView
{
    public $file;

    public function __construct($file)
    {
        $this->file = $file;
    }

    /**
     * Get file contents as plain text for ACE editor
     * @return string
     * @throws Exception
     */
    public function get()
    {
        $startTime = microtime(true);
        // Large file reads can be slow on embedded devices; allow more time.
        @set_time_limit(300);
        // Try to raise memory limit to reduce failures around a few MiB.
        // (May be ignored depending on PHP configuration.)
        @ini_set('memory_limit', '256M');

        $fileSize = filesize($this->file);
        $tailThreshold = 102400; // 100KB

        if ($fileSize > $tailThreshold) {
            // For large files, show the last 150KB to avoid loading huge content
            $tailSize = 153600; // 150KB
            $startPos = max(0, $fileSize - $tailSize);
            $contents = file_get_contents($this->file, false, null, $startPos, $tailSize);
            if ($contents === false) {
                throw new Exception("Cannot get file contents " . $this->file, 3);
            }
            $contents = "... [Showing last 150KB of " . round($fileSize/1024/1024, 2) . "MB file] ...\n\n" . $contents;
        } else {
            // For smaller files, show full content
            $contents = file_get_contents($this->file);
            if ($contents === false) {
                throw new Exception("Cannot get file contents " . $this->file, 3);
            }
        }

        // Ensure valid UTF-8 for JSON encoding
        $contents = self::toValidUtf8($contents);
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        error_log("NfoView::get() - File: {$this->file}, Size: {$fileSize} bytes, Duration: {$duration} seconds");
        
        return $contents;
    }
    
    /**
     * Convert string to valid UTF-8, replacing invalid sequences
     */
    private static function toValidUtf8($str)
    {
        // Try iconv first (more reliable)
        if (function_exists('iconv')) {
            $result = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
            if ($result !== false) {
                return $result;
            }
        }
        
        // Fallback: filter to ASCII + valid UTF-8 continuation
        $result = '';
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($str[$i]);
            // ASCII printable + newline/tab/CR
            if (($byte >= 32 && $byte <= 126) || $byte == 9 || $byte == 10 || $byte == 13) {
                $result .= $str[$i];
            } elseif ($byte >= 194 && $byte <= 244) {
                // Start of multi-byte UTF-8 sequence
                $result .= $str[$i];
            } elseif ($byte >= 128 && $byte <= 191) {
                // Continuation byte
                $result .= $str[$i];
            }
            // Skip other bytes (invalid UTF-8)
        }
        return $result;
    }
}
