<?php

namespace App;

use Core\DataIntegrity;
use Core\Http\Request;
use Core\Http\Response;
use Core\Router;
use Core\Security;
use Core\Validation\Validator;
use Core\View\Renderer;

final class ReservationController
{
    private const WORKSHOP = ['workshop_id' => 17, 'price_cents' => 2750];

    public function __construct(private Renderer $views, private Router $router) {}

    public function show(): Response
    {
        return $this->form();
    }

    public function confirm(Request $request): Response
    {
        $input = $request->attribute('iris.input');
        DataIntegrity::assertCurrent($input, self::WORKSHOP, array_keys(self::WORKSHOP));
        $result = Validator::check($input, [
            'name' => 'required|string|max:80',
            'seats' => 'required|integer|between:1,6',
        ]);
        if (!$result->valid()) {
            return $this->form($input, $result->errors(), 422);
        }

        $values = $result->data();
        $seats = (int) $values['seats'];
        return $this->views->response('confirmation.twig', [
            'name' => trim($values['name']),
            'seats' => $seats,
            'total_cents' => $seats * $input['price_cents'],
            'form_url' => $this->router->url('reservation.form'),
        ]);
    }

    private function form(array $values = [], array $errors = [], int $status = 200): Response
    {
        return $this->views->response('form.twig', [
            'workshop' => self::WORKSHOP,
            'action' => $this->router->url('reservation.confirm'),
            'csrf' => Security::generateCSRFToken(),
            'integrity' => $this->router->integrityToken('reservation.confirm', self::WORKSHOP),
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }
}
