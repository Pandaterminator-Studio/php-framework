<?php

    require '../App/Controllers/Posts.php';
    require '../Core/Router.php';
    $router = new Router();

    $router->add('', ['controller' => 'Home', 'action' => 'index']);
    $router->add('{controller}/{action}');
    $router->add('{controller}/{id:\d+}/{action}');

    $url = $_SERVER['QUERY_STRING'];

    /*if($router->match($url)){
        echo '<pre>';
        echo htmlspecialchars(print_r($router->getRoutes(), true));
        echo '</pre>';
        echo '<pre>';
        var_dump($router->getParams());
        echo '</pre>';
    } else {
        echo "no route found for url '$url'";
    }*/

$router->dispatch($url);