<?php

use Core\Application;

require_once __DIR__ . '/vendor/autoload.php';

return Application::create(__DIR__)->routes(__DIR__ . '/routes/web.php');
