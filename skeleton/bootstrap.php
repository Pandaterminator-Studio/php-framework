<?php

require_once __DIR__ . '/vendor/autoload.php';

if (is_file(__DIR__ . '/.env')) {
    Core\Config::loadEnv(__DIR__ . '/.env');
}

return Core\Application::create(__DIR__)->routes(__DIR__ . '/routes/web.php');
