<?php

$reject = static function (): never {
    http_response_code(404);
    header_remove('X-Powered-By');
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo 'Not found.';
    }
    exit;
};

$request = $_SERVER['REQUEST_URI'] ?? '/';
$path = explode('?', $request, 2)[0];
if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '#')
    || preg_match('/%(?![a-f0-9]{2})|%2f|%5c/i', $path)) {
    $reject();
}
$path = rawurldecode($path);
if (preg_match('/[\x00-\x1f\x7f\\\\%?#]/', $path) || preg_match('~(?:^|/)\.~', $path)
    || (preg_match('~\.(?:php[0-9]*|phps|phtml|pht|phar|inc)(?:[./\x7e]|$)~i', $path) && $path !== '/index.php')) {
    $reject();
}

$root = __DIR__;
$file = $root;
foreach (explode('/', trim($path, '/')) as $segment) {
    $file .= DIRECTORY_SEPARATOR . $segment;
    if (is_link($file)) {
        $reject();
    }
}
if (is_file($file) && $path !== '/index.php') {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $allowed = ['css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'svg', 'eot', 'ttf', 'otf', 'woff', 'woff2'];
    if (!in_array($extension, $allowed, true) || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
        || preg_match('/\.(?:env|ini|conf|config|log|sql|sqlite[0-9]*|db|bak|backup|old|orig|save|swp|swo|pem|key|p12|pfx)(?:[.~]|$)|~$/i', basename($file))) {
        $reject();
    }
    $resolved = realpath($file);
    if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        $reject();
    }
    return false;
}

require $root . '/index.php';
