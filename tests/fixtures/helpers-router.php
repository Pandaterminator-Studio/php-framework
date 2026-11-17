<?php

require dirname(__DIR__, 2) . '/.iris/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler('Core\\Error::errorHandler');
set_exception_handler('Core\\Error::exceptionHandler');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$_SERVER['HTTPS'] = $path === '/http' ? 'off' : 'on';
$cookie = new Core\Cookie(defaultExpiry: $path === '/session' ? 0 : 120, path: '/fixture', domain: 'example.test', secure: $path === '/explicit-insecure' ? false : null);
$cookie->add('sample', 'synthetic');
if ($path === '/delete') {
    $cookie->delete('sample');
}
header('Content-Type: application/json');
echo json_encode(['expiry' => $cookie->getExpiryDate('sample')], JSON_THROW_ON_ERROR);
