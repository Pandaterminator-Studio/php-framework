<?php

use App\ProductCatalog;
use Core\Http\Response;
use Core\Router;

return static function (Router $router): void {
    $router->get('/', static fn(): Response => Response::redirect($router->url('products.index')));
    $router->get('/products', [ProductCatalog::class, 'index'])->name('products.index');
    $router->get('/products/{id:[1-9][0-9]*}', [ProductCatalog::class, 'show'])->name('products.show');
};
