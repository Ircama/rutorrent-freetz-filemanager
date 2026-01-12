<?php
// Filemanager configuration for Freetz-NG

// NOTE: nelu/rutorrent-filemanager expects a $config array.
$config = array();

// Root directory for filemanager.
// Keep it aligned with ruTorrent's top directory (RU_TOP_DIR -> $topDirectory).
global $topDirectory;
$root = $_ENV['RU_TOP_DIR'] ?? $topDirectory ?? '/';
$config['root'] = rtrim($root, '/') . '/';

// Archive support with 7zip
// - `archive.type` controls the formats offered by the *create* dialog.
// - `extensions.fileExtract` controls which files are treated as archives for extract/browse.
$config['archive']['bin'] = '/usr/bin/7z';
$config['archive']['type']['7z'] = array(
	'bin' => '/usr/bin/7z',
	'compression' => array(0, 1, 3, 5, 7, 9),
);
$config['archive']['type']['zip'] = array(
	'bin' => '/usr/bin/7z',
	'compression' => array(0, 1, 3, 5, 7, 9),
);
$config['archive']['type']['tar'] = array(
	'bin' => '/usr/bin/7z',
	'compression' => array(0),
	'has_password' => false,
);

// Archives that can be extracted/browsed (rar/7z/zip/tar/tgz/etc.).
// Used by JS as a RegExp suffix (it appends `$` itself).
$config['extensions']['fileExtract'] = '\\.(?:7z|zip|rar|tar|tgz|gz|bz2|tbz|tbz2|xz|txz|zst|tzst|cab|iso|arj|lha|lzh)$';

// Checksum support with SHA256
$config['extensions']['checksum']['SHA256'] = 'sha256sum';

// Text file extensions
// All file extensions are allowed for viewing (up to plugin limits)
// $config['extensions']['text'] = 'txt|nfo|sfv|md5|csv|log|rc|out';
$config['extensions']['text'] = '\\.(?:txt|nfo|sfv|md5|csv|log|rc|out|ini|conf|cfg|json|xml|html?|css|js|sh|py|pl|php|md)$';

// Mkdir permissions
$config['mkdperm'] = 0755;

// Debug and unicode fix
$config['debug'] = false;
$config['unicode_emoji_fix'] = false;
?>
