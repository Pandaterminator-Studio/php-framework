<?php

    require_once '../vendor/Twig/autoload.php';

    spl_autoload_register(function($class){
        $root = dirname(__DIR__);
        $file = $root.'/'.str_replace("\\", '/', $class) . '.php';
        if(is_readable($file)){
            require $root.'/'.str_replace('\\', '/', $class). '.php';
        }
    });

    set_error_handler('Core\Error::errorHandler');
    set_exception_handler('Core\Error::exceptionHandler');

    require '../Core/Router.php';
    $router = new Core\Router();
    $router->add('', ['controller' => 'Home', 'action' => 'index']);
    $router->add('{controller}/{action}');
    $router->add('{controller}/{action}/{sub_action}');
    $router->add('{controller}/{id:\d+}/{action}');
    $router->add('{controller}/{id:\d+}/{action}/{sub_action}');
    $router->add('admin/{controller}/{action}');
    $router->add('admin/{controller}/{action}/{sub_action}');
    $router->add('admin/{controller}/{id:\d+}/{action}', ['namespace' => 'Admin']);
    $router->add('admin/{controller}/{id:\d+}/{action}/{sub_action}', ['namespace' => 'Admin']);
    $router->dispatch($_SERVER['QUERY_STRING']);