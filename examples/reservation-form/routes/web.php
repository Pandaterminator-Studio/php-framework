<?php

use App\ReservationController;
use Core\Router;

return static function (Router $router): void {
    $router->get('/', [ReservationController::class, 'show'])->name('reservation.form');
    $router->post('/reserveren', [ReservationController::class, 'confirm'])
        ->name('reservation.confirm')
        ->policy([
            'editable' => ['name', 'seats'],
            'protected' => ['workshop_id', 'price_cents'],
        ]);
};
