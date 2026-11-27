<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Extraia ideale-backend ao lado de public_html, fora da raiz pública.
$backend = dirname(__DIR__).'/ideale-backend';

if (file_exists($maintenance = $backend.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $backend.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $backend.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
