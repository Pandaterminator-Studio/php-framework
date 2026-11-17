<?php

return [
    'name' => Core\Config::env('APP_NAME', 'Iris application'),
    'views' => dirname(__DIR__) . '/templates',
    'view_cache' => false,
];
