<?php

use Core\Http\Request;
use Core\Http\Response;
use Core\Testing\TestClient;

$application = require dirname(__DIR__) . '/bootstrap.php';
$application->router()->get('/_test/echo', static fn(Request $request): Response =>
    Response::json(['message' => $request->query('message')])
);

(new TestClient($application))->get('/_test/echo?message=Hello+Iris')
    ->assertStatus(200)
    ->assertJson(['message' => 'Hello Iris']);

echo "Application HTTP test passed.\n";
