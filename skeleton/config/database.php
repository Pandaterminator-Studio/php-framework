<?php

return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'driver' => 'sqlite',
            'database' => dirname(__DIR__) . '/storage/database.sqlite',
        ],
    ],
    'migrations' => dirname(__DIR__) . '/database/migrations',
];
