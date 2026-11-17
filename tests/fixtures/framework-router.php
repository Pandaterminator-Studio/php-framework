<?php

require dirname(__DIR__, 2) . '/.iris/vendor/autoload.php';

use Core\Application;
use Core\Controller;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\Authenticate;
use Core\Middleware\Authorize;
use Core\Security;
use Core\Validation\Validator;

final class FrameworkHttpGreeting
{
    public function value(): string
    {
        return 'synthetic dependency';
    }
}

final class FrameworkHttpController extends Controller
{
    public function __construct(private FrameworkHttpGreeting $greeting)
    {
    }

    public function showAction(Request $request, int $id): Response
    {
        return $this->json(['id' => $id, 'dependency' => $this->greeting->value(), 'method' => $request->method()]);
    }

    public function saveAction(): Response
    {
        return $this->json($this->input());
    }

    public function editAction(int $id): Response
    {
        $values = ['id' => $id, 'price_cents' => 1995, 'owner_id' => 7, 'role' => 'reader'];
        return $this->json(array_merge($values, [
            '_csrf_token' => Security::generateCSRFToken(),
            '_integrity_token' => $this->routeIntegrityToken('controller.form.save', $values),
            'note' => 'Synthetic note',
        ]));
    }

    public function storeAction(): Response
    {
        $_SESSION['framework_fixture_saved'] = ($_SESSION['framework_fixture_saved'] ?? 0) + 1;
        header('X-Fixture-Saved: yes');
        return $this->json($this->input());
    }
}

$application = Application::create(__DIR__);
$application->config()->set('http.structured_json', true);
$router = $application->router();
$application->middleware(static function (Request $request, callable $next): Response {
    return $next($request)->withHeader('X-Fixture-Kernel', 'active');
});
$router->get('/echo', static fn(Request $request): Response => Response::json(['method' => $request->method(), 'query' => $request->query()]));
$router->get('/typed/{id}', static fn(int $id): Response => Response::json(['id' => $id]))->name('typed.show');
$router->get('/controller/{id:\d+}', [FrameworkHttpController::class, 'show']);
$router->get('/url', static fn(): Response => Response::json(['url' => $router->url('typed.show', ['id' => 42], ['q' => 'a&b'])]));
$router->get('/error', static fn(): never => throw new RuntimeException('Synthetic private exception token'));
$router->get('/redirect', static fn(): Response => Response::redirect('/echo'));
$router->get('/unsafe-redirect', static fn(): Response => Response::redirect('https://example.test/blocked'));
$router->post('/write-denied', static fn(): Response => Response::text('Must not execute'));
$router->post('/stateless-unchecked', static fn(): Response => Response::text('Must not execute'))
    ->stateless()->policy(['editable' => [], 'protected' => []]);

$authenticate = new Authenticate(static fn(Request $request): ?array => $request->header('Authorization') === 'Bearer iris-synthetic-test-token'
    ? ['id' => '7', 'role' => 'reader'] : null);
$router->get('/private/{id:\d+}', static fn(): Response => Response::json(['authorized' => true]))
    ->middleware($authenticate, new Authorize(static fn(array $principal, Request $request): bool => $principal['id'] === $request->attribute('route.parameters')['id']));
$router->group('/api', [$authenticate], static function ($routes): void {
    $routes->post('/echo', static fn(Request $request): Response => Response::json($request->attribute('iris.input')))
        ->stateless()->policy(['editable' => ['note'], 'protected' => ['role', 'owner_id']]);
    $routes->post('/controller', [FrameworkHttpController::class, 'save'])
        ->stateless()->policy(['editable' => ['note'], 'protected' => ['role', 'owner_id']]);
    $routes->post('/nested', static fn(Request $request): Response => Response::json($request->attribute('iris.input')))
        ->stateless()->policy(['editable' => ['metadata'], 'protected' => []]);
    $routes->post('/validate', static fn(Request $request): Response => Response::json(Validator::validate($request->attribute('iris.input'), ['email' => 'required|string|email'])))
        ->stateless()->policy(['editable' => ['email'], 'protected' => []]);
});

$policy = ['editable' => ['note'], 'protected' => ['id', 'price_cents', 'owner_id', 'role']];
$router->get('/controller-form/{id:\d+}/edit', [FrameworkHttpController::class, 'edit']);
$router->post('/controller-form/{id:\d+}', [FrameworkHttpController::class, 'store'])->name('controller.form.save')->policy($policy);
$router->post('/controller-form-alias/{id:\d+}', [FrameworkHttpController::class, 'store'])->name('controller.form.alias')->policy($policy);
$save = static function (Request $request): Response {
    $_SESSION['framework_fixture_saved'] = ($_SESSION['framework_fixture_saved'] ?? 0) + 1;
    header('X-Fixture-Saved: yes');
    return Response::json($request->attribute('iris.input'));
};
$router->post('/form/{id:\d+}', $save)->name('form.save')->policy($policy);
$router->post('/other-form/{id:\d+}', $save)->name('form.other')->policy($policy);
$router->get('/form', static function () use ($router): Response {
    $values = ['id' => 42, 'price_cents' => 1995, 'owner_id' => 7, 'role' => 'reader'];
    return Response::json(array_merge($values, [
        '_csrf_token' => Security::generateCSRFToken(),
        '_integrity_token' => $router->integrityToken('form.save', $values, ['id' => '42']),
        'note' => 'Synthetic note',
    ]));
});
$router->get('/form-status', static function (): Response {
    Core\Session::start();
    return Response::json(['saved' => $_SESSION['framework_fixture_saved'] ?? 0]);
});

$application->run();
