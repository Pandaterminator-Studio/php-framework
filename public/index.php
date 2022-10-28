<?php

    require_once '../vendor/Twig/autoload.php';

    spl_autoload_register(function($class){
        $root = dirname(__DIR__);
        $file = $root.'/'.str_replace("\\", '/', $class) . '.php';
        if(is_readable($file)){
            require $root.'/'.str_replace('\\', '/', $class). '.php';
        }
    });

    require '../Core/Router.php';
    $router = new Core\Router();
    $router->add('', ['controller' => 'Home', 'action' => 'index']);
    $router->add('{controller}/{action}');
    $router->add('{controller}/{id:\d+}/{action}');
    $router->add('admin/{controller}/{action}');
    $router->add('admin/{controller}/{id:\d+}/{action}', ['namespace' => 'Admin']);
    $router->dispatch($_SERVER['QUERY_STRING']);