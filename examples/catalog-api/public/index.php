<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');

try {
    $application = require dirname(__DIR__) . '/bootstrap.php';
    if (!$application instanceof Core\Application) {
        throw new LogicException('Invalid application bootstrap.');
    }
    $application->run();
} catch (Throwable $exception) {
    http_response_code(500);
    header_remove('X-Powered-By');
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo 'Internal server error.';
    }
}
