<?php

namespace {
    require dirname(__DIR__) . '/.iris/vendor/autoload.php';
    ob_start();
}

namespace KernelFixtures {
    interface OptionalService {}
    final class RequestMiddleware implements \Core\Middleware\MiddlewareInterface
    {
        public function __construct(private \Core\Http\Request $injected) {}

        public function process(\Core\Http\Request $request, callable $next): \Core\Http\Response
        {
            if ($this->injected !== $request) {
                throw new \LogicException('Middleware was constructed with another request.');
            }
            return $next($request);
        }
    }
    final class FirstProvider extends \Core\ServiceProvider
    {
        public function register(\Core\Container $container, \Core\Config $config): void { $config->set('providers.first', true); }
        public function boot(\Core\Application $application): void { $application->config()->set('providers.ready', $application->config()->get('providers.second', false)); }
    }
    final class SecondProvider extends \Core\ServiceProvider
    {
        public function register(\Core\Container $container, \Core\Config $config): void { $config->set('providers.second', true); }
    }
    final class RequestController extends \Core\Controller
    {
        public function __construct(private \Core\Http\Request $injected) {}
        public function indexAction(): \Core\Http\Response { return $this->json(['user' => $this->injected->attribute('user')]); }
    }
    final class Greeting
    {
        public function message(): string { return 'injected'; }
    }

    final class ExampleController extends \Core\Controller
    {
        public function __construct(private Greeting $greeting) {}
        protected function requestPolicies(): array
        {
            return ['save' => ['methods' => ['POST'], 'editable' => ['note'], 'protected' => ['id']]];
        }
        public function showAction(int $id): \Core\Http\Response
        {
            return $this->json(['id' => $id, 'message' => $this->greeting->message()]);
        }
        public function saveAction(): \Core\Http\Response
        {
            return $this->json($this->input());
        }
        public function templateAction(): \Core\Http\Response
        {
            return $this->view('hello.html', ['name' => '<safe>']);
        }
    }
}

namespace {
    use Core\Application;
    use Core\Config;
    use Core\Http\Request;
    use Core\Http\Response;
    use Core\Middleware\Authenticate;
    use Core\Middleware\Authorize;
    use Core\Security;
    use Core\Validation\Validator;
    use KernelFixtures\ExampleController;

    function kernelEnsure(bool $condition): void
    {
        if (!$condition) { throw new \RuntimeException('Kernel assertion failed.'); }
    }
    function kernelJson(Response $response): array
    {
        return json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
    }
    $directory = sys_get_temp_dir() . '/iris-kernel-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory . '/templates', 0700);
    mkdir($directory . '/sessions', 0700);
    file_put_contents($directory . '/templates/hello.html', 'Hello {{ name }}');
    ini_set('session.save_path', $directory . '/sessions');
    ini_set('error_log', $directory . '/errors.log');
    $make = static fn() => new Application($directory, new Config(['app' => ['views' => $directory . '/templates']]));
    $tests = [];
    $tests['an application boots without a website or database'] = static function () use ($directory): void {
        $app = Application::create($directory);
        kernelEnsure($app->handle(new Request('GET', '/missing'))->status() === 404);
    };
    $tests['typed route parameters and request objects are injected'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('/items/{id:\d+}', static fn(Request $request, int $id) => Response::json(['method' => $request->method(), 'id' => $id]));
        $response = $app->handle(new Request('GET', '/items/42'));
        kernelEnsure($response->status() === 200 && kernelJson($response) === ['method' => 'GET', 'id' => 42]);
        kernelEnsure($response->header('X-Content-Type-Options') === 'nosniff');
    };
    $tests['named routes encode parameters and query values'] = static function () use ($make): void {
        $router = $make()->router();
        $router->get('files/{name}', static fn() => Response::text('ok'))->name('files.show');
        kernelEnsure($router->url('files.show', ['name' => 'a b'], ['q' => 'a&b']) === '/files/a%20b?q=a%26b');
    };
    $tests['route constraints are preserved and malformed typed IDs are rejected'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('slug/{value:[a-z-]+}', static fn(string $value) => Response::text($value));
        kernelEnsure($app->handle(new Request('GET', '/slug/42'))->status() === 404);
        $app->router()->get('typed/{id}', static fn(int $id) => Response::json(['id' => $id]));
        kernelEnsure($app->handle(new Request('GET', '/typed/overflow'))->status() === 400);
    };
    $tests['method mismatches advertise allowed methods'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('read', static fn() => Response::text('ok'));
        $response = $app->handle(new Request('POST', '/read'));
        kernelEnsure($response->status() === 405 && $response->header('Allow') === 'GET, HEAD');
    };
    $tests['groups and middleware wrap handlers in registration order'] = static function () use ($make): void {
        $events = [];
        $middleware = static function (string $name) use (&$events): \Closure {
            return static function (Request $request, callable $next) use ($name, &$events): Response {
                $events[] = $name . ':before';
                $response = $next($request);
                $events[] = $name . ':after';
                return $response;
            };
        };
        $app = $make();
        $app->middleware($middleware('global'));
        $app->router()->group('api', [$middleware('group')], static function ($router) use ($middleware, &$events): void {
            $router->get('ping', static function () use (&$events): Response { $events[] = 'handler'; return Response::text('ok'); })->middleware($middleware('route'));
        });
        kernelEnsure($app->handle(new Request('GET', '/api/ping'))->status() === 200);
        kernelEnsure($events === ['global:before', 'group:before', 'route:before', 'handler', 'route:after', 'group:after', 'global:after']);
    };
    $tests['controller constructors and action arguments receive dependencies'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('controller/{id:\d+}', [ExampleController::class, 'show']);
        kernelEnsure(kernelJson($app->handle(new Request('GET', '/controller/8'))) === ['id' => 8, 'message' => 'injected']);
    };
    $tests['controllers render application-owned templates'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('template', [ExampleController::class, 'template']);
        $response = $app->handle(new Request('GET', '/template'));
        kernelEnsure($response->status() === 200 && $response->body() === 'Hello &lt;safe&gt;');
    };
    $tests['unconfigured session writes remain denied'] = static function () use ($make): void {
        $app = $make();
        $app->router()->post('write', static fn() => Response::text('should not execute'));
        kernelEnsure($app->handle(new Request('POST', '/write'))->status() === 405);
    };
    $tests['named session routes enforce and consume immutable snapshots'] = static function () use ($make): void {
        $app = $make();
        $app->router()->post('items/{id:\d+}', static fn(Request $request) => Response::json($request->attribute('iris.input')))
            ->name('items.save')->policy(['editable' => ['note'], 'protected' => ['id']]);
        $token = $app->router()->integrityToken('items.save', ['id' => 42], ['id' => '42']);
        $body = ['id' => '42', 'note' => 'ok', '_integrity_token' => $token, '_csrf_token' => Security::generateCSRFToken()];
        kernelEnsure(kernelJson($app->handle(new Request('POST', '/items/42', [], [], $body))) === ['note' => 'ok', 'id' => 42]);
        kernelEnsure($app->handle(new Request('POST', '/items/42', [], [], $body))->status() === 403);
    };
    $tests['controller write policies remain mandatory and use trusted IDs'] = static function () use ($make): void {
        $app = $make();
        $app->router()->post('controller/{id:\d+}', [ExampleController::class, 'save'])->name('controller.save');
        $token = $app->router()->integrityToken('controller.save', ['id' => 9], ['id' => '9']);
        $body = ['id' => '9', 'note' => 'ok', '_integrity_token' => $token, '_csrf_token' => Security::generateCSRFToken()];
        kernelEnsure(kernelJson($app->handle(new Request('POST', '/controller/9', [], [], $body))) === ['note' => 'ok', 'id' => 9]);
    };
    $tests['stateless routes cannot bypass authentication by declaring a mode'] = static function () use ($make): void {
        $app = $make();
        $app->router()->post('api', static fn() => Response::text('should not execute'))->stateless()->policy(['editable' => [], 'protected' => []]);
        kernelEnsure($app->handle(new Request('POST', '/api'))->status() === 401);
    };
    $tests['authenticated APIs accept declared editable fields and reject protected fields'] = static function () use ($make): void {
        $app = $make();
        $auth = new Authenticate(static fn(Request $request) => $request->header('Authorization') === 'Bearer synthetic' ? ['id' => 7] : null);
        $app->router()->post('api', static fn(Request $request) => Response::json($request->attribute('iris.input')))
            ->middleware($auth)->stateless()->policy(['editable' => ['note'], 'protected' => ['role']]);
        kernelEnsure($app->handle(new Request('POST', '/api', [], [], ['note' => 'ok']))->status() === 401);
        kernelEnsure(kernelJson($app->handle(new Request('POST', '/api', ['Authorization' => 'Bearer synthetic'], [], ['note' => 'ok']))) === ['note' => 'ok']);
        kernelEnsure($app->handle(new Request('POST', '/api', ['Authorization' => 'Bearer synthetic'], [], ['role' => 'admin']))->status() === 403);
    };
    $tests['authorization policies receive principal and route context'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('private/{id:\d+}', static fn() => Response::text('allowed'))
            ->middleware(new Authenticate(static fn() => ['id' => '7']), new Authorize(static fn($user, Request $request) => $user['id'] === $request->attribute('route.parameters')['id']));
        kernelEnsure($app->handle(new Request('GET', '/private/7'))->status() === 200);
        kernelEnsure($app->handle(new Request('GET', '/private/8'))->status() === 403);
    };
    $tests['validation errors become structured safe API responses'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('validate', static function (): Response { Validator::validate(['email' => 'bad'], ['email' => 'required|email']); return Response::text('unreachable'); });
        $response = $app->handle(new Request('GET', '/validate', ['Accept' => 'application/json']));
        kernelEnsure($response->status() === 422 && isset(kernelJson($response)['errors']['email']));
    };
    $tests['unhandled errors never disclose exception details'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('error', static function (): never { throw new \RuntimeException('synthetic-private-detail'); });
        $response = $app->handle(new Request('GET', '/error', ['Accept' => 'application/json']));
        kernelEnsure($response->status() === 500 && !str_contains($response->body(), 'synthetic-private-detail'));
    };
    $tests['controller object handlers receive guarded request context'] = static function () use ($make): void {
        $app = $make();
        $controller = new ExampleController(new \KernelFixtures\Greeting());
        $app->router()->get('object/{id:\d+}', [$controller, 'showAction']);
        kernelEnsure(kernelJson($app->handle(new Request('GET', '/object/4'))) === ['id' => 4, 'message' => 'injected']);
    };
    $tests['controller constructors receive the authenticated current request'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('context', [\KernelFixtures\RequestController::class, 'index'])->middleware(new Authenticate(static fn() => ['id' => 7]));
        kernelEnsure(kernelJson($app->handle(new Request('GET', '/context'))) === ['user' => ['id' => 7]]);
    };
    $tests['request instances follow middleware and nested dispatch lifetimes'] = static function () use ($make): void {
        $app = $make();
        $container = $app->container();
        $outside = new Request('GET', '/outside');
        $container->instance(Request::class, $outside);
        $app->middleware(\KernelFixtures\RequestMiddleware::class);
        $app->router()->get('inner', static fn() => throw new \RuntimeException('Nested failure.'));
        $app->router()->get('outer', static function (Request $request) use ($app, $container): Response {
            kernelEnsure($request === $container->get(Request::class));
            kernelEnsure($request->attribute('iris.input') === ['page' => '2']);
            kernelEnsure($app->handleHttp('GET', '/inner')->status() === 500);
            kernelEnsure($request === $container->get(Request::class));
            return Response::text('outer request restored');
        })->middleware(new Authenticate(static fn() => 'user'), \KernelFixtures\RequestMiddleware::class);
        kernelEnsure($app->handleHttp('GET', '/outer?page=2')->body() === 'outer request restored');
        kernelEnsure($container->get(Request::class) === $outside);
    };
    $tests['a completed dispatch releases its request binding'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('ok', static fn() => Response::text('OK'));
        kernelEnsure($app->handleHttp('GET', '/ok')->status() === 200);
        kernelEnsure(!$app->container()->bound(Request::class));
        kernelEnsure($app->handleHttp('GET', '/missing')->status() === 404);
        kernelEnsure(!$app->container()->bound(Request::class));
    };
    $tests['short-circuiting skips construction of downstream middleware'] = static function () use ($make): void {
        $app = $make();
        $app->middleware(static fn() => Response::text('Stopped', 401), 'unreachable-service');
        kernelEnsure($app->handleHttp('GET', '/')->status() === 401);
        kernelEnsure(!$app->container()->bound(Request::class));
    };
    $tests['raw adapters negotiate parse errors before middleware'] = static function () use ($make): void {
        $app = $make();
        $executed = false;
        $app->middleware(static function () use (&$executed): Response {
            $executed = true;
            return Response::text('Unexpected');
        });
        $response = $app->handleHttp('POST', '/', ['Content-Type' => 'application/json'], '{"amount":');
        kernelEnsure(!$executed && $response->status() === 400 && isset(kernelJson($response)['message']));
        kernelEnsure($response->header('X-Frame-Options') === 'DENY');
    };
    $tests['optional unbound service arguments retain their default'] = static function () use ($make): void {
        $app = $make();
        $app->router()->get('optional', static fn(?\KernelFixtures\OptionalService $service = null) => Response::json(['default' => $service === null]));
        kernelEnsure(kernelJson($app->handle(new Request('GET', '/optional'))) === ['default' => true]);
    };
    $tests['broken logger factories cannot break error responses'] = static function () use ($make): void {
        $app = $make();
        $app->container()->bind(\Psr\Log\LoggerInterface::class, static fn() => throw new \RuntimeException('private logger error'));
        $app->router()->get('error', static fn() => throw new \RuntimeException('private handler error'));
        $response = $app->handle(new Request('GET', '/error', ['Accept' => 'application/json']));
        kernelEnsure($response->status() === 500 && !str_contains($response->body(), 'private'));
    };
    $tests['explicit service bindings survive application construction'] = static function () use ($directory): void {
        $container = new \Core\Container();
        $cache = new \Core\Cache\ArrayCache();
        $container->instance(\Core\Cache\CacheInterface::class, $cache);
        $app = new Application($directory, new Config(), $container);
        kernelEnsure($app->container()->get(\Core\Cache\CacheInterface::class) === $cache);
    };
    $tests['providers register fully before boot runs'] = static function () use ($directory): void {
        $app = new Application($directory, new Config(['app' => ['providers' => [new \KernelFixtures\FirstProvider(), new \KernelFixtures\SecondProvider()]]]));
        kernelEnsure($app->config()->get('providers.ready') === true);
    };
    $tests['database connections and PDO are lazily shared and injectable'] = static function () use ($make): void {
        $app = $make();
        $app->config()->set('database', ['default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        $connection = $app->container()->get(\Core\Database\Connection::class);
        kernelEnsure($connection->pdo() === $app->container()->get(\PDO::class));
        $app->router()->get('db', static fn(\Core\Database\Connection $db) => Response::json($db->first('SELECT 1 AS value')));
        kernelEnsure(kernelJson($app->handle(new Request('GET', '/db'))) === ['value' => 1]);
    };
    $tests['CORS validates preflight and decorates guarded errors'] = static function () use ($make): void {
        $app = $make();
        $app->middleware(new \Core\Middleware\Cors(['https://client.example'], ['GET', 'POST'], ['Content-Type', 'Authorization']));
        $app->router()->get('private', static fn() => Response::text('private'))->middleware(new Authenticate(static fn() => null));
        $preflight = $app->handle(new Request('OPTIONS', '/private', ['Origin' => 'https://client.example', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization']));
        kernelEnsure($preflight->status() === 204 && $preflight->header('Access-Control-Allow-Origin') === 'https://client.example');
        $denied = $app->handle(new Request('GET', '/private', ['Origin' => 'https://client.example']));
        kernelEnsure($denied->status() === 401 && $denied->header('Access-Control-Allow-Origin') === 'https://client.example');
        kernelEnsure($app->handle(new Request('GET', '/private', ['Origin' => 'https://other.example']))->status() === 403);
        foreach (['https://user@client.example', 'https://client.example/path', 'https://client.example?query', 'https://client.example#fragment'] as $origin) {
            try { new \Core\Middleware\Cors([$origin]); throw new \RuntimeException('Invalid origin accepted.'); }
            catch (\InvalidArgumentException) {}
        }
    };
    $tests['request IDs remain available on guarded error responses'] = static function () use ($make): void {
        $app = $make();
        $app->middleware(new \Core\Middleware\RequestId());
        $response = $app->handle(new Request('GET', '/unknown'));
        kernelEnsure($response->status() === 404 && preg_match('/^[a-f0-9]{32}$/D', $response->header('X-Request-Id')) === 1);
    };
    $tests['named routes reject dot segments duplicate names and extra parameters'] = static function () use ($make): void {
        $router = $make()->router();
        $router->get('files/{id}', static fn() => Response::text('ok'))->name('file');
        foreach ([['id' => '.'], ['id' => '..'], ['id' => 'ok', 'typo' => 'x']] as $parameters) {
            try { $router->url('file', $parameters); throw new \RuntimeException('Invalid URL accepted.'); }
            catch (\InvalidArgumentException) {}
        }
        try { $router->get('other', static fn() => Response::text('ok'))->name('file'); throw new \RuntimeException('Duplicate name accepted.'); }
        catch (\InvalidArgumentException) {}
    };
    $tests['modern route parameters named action remain bound to challenges'] = static function () use ($make): void {
        $app = $make();
        $app->router()->post('special/{action}', [ExampleController::class, 'save'])->name('special.save');
        $token = $app->router()->integrityToken('special.save', ['id' => 9], ['action' => 'one']);
        $body = ['id' => '9', 'note' => 'ok', '_integrity_token' => $token, '_csrf_token' => Security::generateCSRFToken()];
        kernelEnsure($app->handle(new Request('POST', '/special/two', [], [], $body))->status() === 403);
        kernelEnsure($app->handle(new Request('POST', '/special/one', [], [], $body))->status() === 200);
    };
    $passed = 0;
    $failures = [];
    foreach ($tests as $name => $test) {
        try { $test(); $passed++; }
        catch (\Throwable $exception) { $failures[] = $name . ': ' . get_class($exception) . ' ' . $exception->getMessage(); }
    }
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    foreach (glob($directory . '/sessions/*') ?: [] as $file) { unlink($file); }
    if (is_file($directory . '/errors.log')) { unlink($directory . '/errors.log'); }
    unlink($directory . '/templates/hello.html');
    rmdir($directory . '/templates'); rmdir($directory . '/sessions'); rmdir($directory);
    ob_end_clean();
    foreach ($failures as $failure) { fwrite(STDERR, $failure . PHP_EOL); }
    echo $passed . ' kernel tests passed; ' . count($failures) . ' failed.' . PHP_EOL;
    exit($failures === [] ? 0 : 1);
}
