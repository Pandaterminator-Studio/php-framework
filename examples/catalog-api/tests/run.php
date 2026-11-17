<?php

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Testing\TestClient;

$app = require dirname(__DIR__) . '/bootstrap.php';
$app->config()->set('database', ['driver' => 'sqlite', 'database' => ':memory:']);
$migrator = new Migrator($app->container()->get(Connection::class), $app->basePath('database/migrations'));
if ($migrator->migrate() !== ['001_create_products.php'] || $migrator->migrate() !== []) {
    throw new RuntimeException('De migratie moet precies één keer worden uitgevoerd.');
}

$client = new TestClient($app);
$headers = ['Accept' => 'application/json'];
$coffee = ['id' => 2, 'name' => 'Filterkoffie', 'category' => 'koffie', 'price_cents' => 750];
$client->get('/')->assertStatus(302)->assertHeader('Location', '/products');
$client->get('/products/2', $headers)->assertStatus(200)->assertJson(['data' => $coffee]);
$client->get('/products?category=koffie&page=2&per_page=1', $headers)->assertStatus(200)->assertJson([
    'data' => [$coffee],
    'meta' => ['current_page' => 2, 'per_page' => 1, 'total' => 2, 'last_page' => 2],
]);
$client->get('/products?category=onbekend', $headers)->assertStatus(200)->assertJson([
    'data' => [],
    'meta' => ['current_page' => 1, 'per_page' => 3, 'total' => 0, 'last_page' => 1],
]);
$client->get('/products/999', $headers)->assertStatus(404)->assertJson(['message' => 'Product niet gevonden.']);
$client->get('/products/koffie', $headers)->assertStatus(404);
foreach (['page=0', 'page=1.5', 'per_page=101', 'per_page=0'] as $invalidQuery) {
    $client->get('/products?' . $invalidQuery, $headers)->assertStatus(422);
}
$client->request('POST', '/products', $headers)->assertStatus(405);

echo "Catalogus-API geslaagd.\n";
