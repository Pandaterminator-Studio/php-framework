<?php

namespace Core;

use Core\Database\DatabaseManager;
use Core\Database\Connection;
use Core\Cache\CacheInterface;
use Core\Cache\ArrayCache;
use Core\Cache\FileCache;
use Core\Events\Dispatcher;
use Core\Http\ErrorHandler;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\Pipeline;
use Core\Queue\DatabaseQueue;
use Core\Queue\Worker;
use Core\View\Renderer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Application
{
    private Container $container;
    private Config $config;
    private Router $router;
    private array $middleware = [];

    public function __construct(private string $basePath, ?Config $config = null, ?Container $container = null)
    {
        $resolved = realpath($basePath);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('An existing application directory is required.');
        }
        $this->basePath = $resolved;
        $this->config = $config ?? (is_dir($resolved . '/config') ? Config::fromDirectory($resolved . '/config') : new Config());
        $this->container = $container ?? new Container();
        $this->router = new Router($this->container);
        $this->container->instance(self::class, $this);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        if (!$this->container->has(LoggerInterface::class)) {
            $this->container->instance(LoggerInterface::class, new NullLogger());
        }
        $defaults = [
            Renderer::class => fn() => new Renderer($this->config->get('app.views', $this->basePath . '/templates'), $this->config->get('app.view_cache', false)),
            DatabaseManager::class => fn() => new DatabaseManager($this->config->get('database', ['default' => 'default', 'connections' => []])),
            Connection::class => fn() => $this->container->get(DatabaseManager::class)->defaultConnection(),
            \PDO::class => fn() => $this->container->get(Connection::class)->pdo(),
            Dispatcher::class => static fn() => new Dispatcher(),
            DatabaseQueue::class => fn() => new DatabaseQueue($this->container->get(Connection::class)),
            Worker::class => fn() => new Worker(
                $this->container->get(DatabaseQueue::class),
                $this->container,
                $this->config->get('queue.handlers', []),
                $this->config->get('queue.lease_seconds', 60),
                $this->config->get('queue.retry_delay', 5),
                $this->container->get(LoggerInterface::class),
            ),
            CacheInterface::class => function (): CacheInterface {
            return match ($this->config->get('cache.driver', 'array')) {
                'array' => new ArrayCache(),
                'file' => new FileCache($this->config->get('cache.path', $this->basePath . '/storage/cache')),
                default => throw new \InvalidArgumentException('Unknown cache driver.'),
            };
            },
        ];
        foreach ($defaults as $id => $factory) {
            if (!$this->container->bound($id)) {
                $this->container->singleton($id, $factory);
            }
        }
        $providers = [];
        foreach ($this->config->get('app.providers', []) as $provider) {
            $provider = is_string($provider) ? $this->container->get($provider) : $provider;
            if (!$provider instanceof ServiceProvider) {
                throw new \InvalidArgumentException('Application providers must extend ServiceProvider.');
            }
            $provider->register($this->container, $this->config);
            $providers[] = $provider;
        }
        foreach ($providers as $provider) {
            $provider->boot($this);
        }
    }

    public static function create(string $basePath): self
    {
        return new self($basePath);
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    public function config(): Config { return $this->config; }
    public function container(): Container { return $this->container; }
    public function router(): Router { return $this->router; }

    public function middleware(mixed ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    public function routes(string $file): self
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException('Route configuration file not found.');
        }
        $register = require $file;
        if (!is_callable($register)) {
            throw new \LogicException('Route files must return a registration callback.');
        }
        $register($this->router, $this);
        return $this;
    }

    public function handle(Request $request): Response
    {
        try {
            $response = (new Pipeline($this->middleware, $this->container))->handle($request, function (Request $current): Response {
                try {
                    return $this->router->handle($current);
                } catch (\Throwable $exception) {
                    return $this->errorResponse($exception, $current);
                }
            });
        } catch (\Throwable $exception) {
            $response = $this->errorResponse($exception, $request);
        }
        return $this->withDefaultHeaders($response);
    }

    public function handleHttp(string $method, string $uri, array $headers = [], string $rawBody = '', array $cookies = [], array $server = []): Response
    {
        try {
            $request = Request::fromHttp($method, $uri, $headers, $rawBody, $this->config->get('http.structured_json', false) === true, $cookies, $server);
        } catch (\Throwable $exception) {
            return $this->parseErrorResponse($exception, $headers);
        }
        return $this->handle($request);
    }

    private function withDefaultHeaders(Response $response): Response
    {
        foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'same-origin', 'Cache-Control' => 'no-store'] as $name => $value) {
            if ($response->header($name) === null) {
                $response = $response->withHeader($name, $value);
            }
        }
        return $response;
    }

    public function run(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        set_error_handler([Error::class, 'errorHandler']);
        try {
            try {
                $request = Request::fromGlobals($this->config->get('http.structured_json', false) === true);
                $response = $this->handle($request);
            } catch (\Throwable $exception) {
                $request = null;
                $response = $this->parseErrorResponse($exception, ['Accept' => $_SERVER['HTTP_ACCEPT'] ?? null, 'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? null]);
            }
            header_remove('X-Powered-By');
            $response->send(($request?->method() ?? $_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        } finally {
            restore_error_handler();
        }
    }

    private function errorResponse(\Throwable $exception, ?Request $request = null): Response
    {
        try {
            $logger = $this->container->get(LoggerInterface::class);
            $logger = $logger instanceof LoggerInterface ? $logger : null;
        } catch (\Throwable) {
            $logger = null;
        }
        return (new ErrorHandler($logger))->response($exception, $request);
    }

    private function parseErrorResponse(\Throwable $exception, array $headers): Response
    {
        $request = null;
        try {
            $negotiation = [];
            foreach ($headers as $name => $value) {
                if (is_string($name) && is_string($value) && in_array(strtolower($name), ['accept', 'content-type'], true)) {
                    $negotiation[$name] = $value;
                }
            }
            $request = new Request('GET', '/', $negotiation);
        } catch (\Throwable) {
        }
        return $this->withDefaultHeaders($this->errorResponse($exception, $request));
    }
}
