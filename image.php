<?php

use Flm\Helper;
use Flm\WebController;

require_once(__DIR__ . '/boot.php');

$c = new WebController(Helper::getConfig());

// This endpoint is intentionally GET-friendly so it can be used as <img src="...">.
$params = (object)[
    'target' => $_GET['target'] ?? null,
];

$c->viewImage($params);
