<?php

use Flm\Helper;
use Flm\WebController;

$pluginDir = dirname(__FILE__);

require_once($pluginDir . "/boot.php");


$pluginConfig = Helper::getConfig();
if (function_exists('findRemoteEXE'))
{
    // Binary validation removed - Archive.php resolves the actual binary path via Utility::getExternal()
    
    $theSettings->registerEventHook($plugin["name"], "TaskSuccess", 10, true);
    $theSettings->registerPlugin("filemanager");

    $c = new WebController($pluginConfig);
    $jResult .= 'plugin.config = ' . json_encode($c->getConfig()) . ';';
}

