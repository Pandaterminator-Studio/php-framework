<?php

    require_once '../vendor/Twig/autoload.php';

    spl_autoload_register(function($class){
        $root = dirname(__DIR__);
        $file = $root.'/'.str_replace("\\", '/', $class) . '.php';
        if(is_readable($file)){
            require $root.'/'.str_replace('\\', '/', $class). '.php';
        }
    });

    error_reporting(E_ALL);
    set_error_handler('Core\Error::errorHandler');
    set_exception_handler('Core\Error::exceptionHandler');

    require '../Core/Router.php';
    $router = new Core\Router();
    $router->add('', ['controller' => 'Home', 'action' => 'index']);
    $router->add('{controller}/{action}');
    $router->add('{controller}/{action}/{id:\d+}');
    $router->dispatch($_SERVER['QUERY_STRING']);