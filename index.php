<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = dirname(__DIR__).'/droopnexa-private';
if (! is_file($appPath.'/vendor/autoload.php') || ! is_file($appPath.'/.deployment-ready')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'The service is being configured. Please try again shortly.']);
    exit;
}

if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';
$app->handleRequest(Request::capture());
