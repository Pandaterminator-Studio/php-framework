<?php

use Core\Database\Connection;
use Core\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(Connection $connection): void
    {
        $connection->execute('CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            price_cents INTEGER NOT NULL CHECK (price_cents >= 0)
        )');
        $products = [
            ['Espressobonen', 'koffie', 895],
            ['Filterkoffie', 'koffie', 750],
            ['Groene thee', 'thee', 495],
            ['Kamillebloesem', 'thee', 425],
            ['Havermelk', 'zuivelalternatief', 225],
        ];
        foreach ($products as [$name, $category, $price]) {
            $connection->table('products')->insert(['name' => $name, 'category' => $category, 'price_cents' => $price]);
        }
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE products');
    }
};
