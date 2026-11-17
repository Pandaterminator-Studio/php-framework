<?php

    require_once dirname(__DIR__) . '/.iris/vendor/autoload.php';

    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    set_error_handler('Core\Error::errorHandler');
    set_exception_handler('Core\Error::exceptionHandler');

    Core\Session::start();
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');

    $router = new Core\Router();
    $router->add('', ['controller' => 'Home', 'action' => 'index']);
    $router->add('{controller}/{action}');
    $router->add('{controller}/{action}/{id:\d+}');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path)) {
        throw new Core\HttpException(400, 'Invalid request path.');
    }
    $path = trim($path, '/');
    if ($path === 'index.php') {
        $path = '';
    }
    $router->dispatch($path, Core\RequestGuard::fromGlobals());
