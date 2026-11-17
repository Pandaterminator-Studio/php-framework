<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect') {
    header('Location: /download', true, 302);
    return;
}
if ($path === '/loop') {
    header('Location: /loop', true, 302);
    return;
}
if ($path === '/file-redirect') {
    header('Location: file://' . __FILE__, true, 302);
    return;
}
if ($path === '/download') {
    header('Content-Type: application/octet-stream');
    echo "Synthetic download\n";
    return;
}
if ($path === '/missing') {
    http_response_code(404);
    echo 'Synthetic missing document';
    return;
}
if ($path === '/slow') {
    usleep(1800000);
}
$files = [];
foreach ($_FILES as $name => $file) {
    $files[$name] = [
        'name' => $file['name'],
        'content' => file_get_contents($file['tmp_name']),
    ];
}
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'query' => $_GET,
    'raw' => file_get_contents('php://input'),
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
    'marker' => $_SERVER['HTTP_X_FIXTURE_MARKER'] ?? '',
    'fields' => $_POST,
    'files' => $files,
], JSON_THROW_ON_ERROR);
