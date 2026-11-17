<?php

namespace Core\Routing;

use Core\Container;
use Core\Controller;
use Core\DataIntegrity;
use Core\Http\Request;
use Core\Http\Response;
use Core\HttpException;
use Core\Middleware\Pipeline;

trait FrameworkRoutes
{
    private array $frameworkRoutes = [];
    private array $groups = [];
    private ?Container $container = null;

    public function __construct(?Container $container = null)
    {
        $this->container = $container;
    }

    public function map(array|string $methods, string $path, mixed $handler): Route
    {
        $methods = is_string($methods) ? [$methods] : $methods;
        if (!array_is_list($methods) || $methods === [] || array_diff($methods, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])) {
            throw new \InvalidArgumentException('Invalid route methods.');
        }
        if (is_string($handler) && str_contains($handler, '@')) {
            $handler = explode('@', $handler, 2);
        }
        if (!is_callable($handler) && !(is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1]))
            && !(is_string($handler) && class_exists($handler))) {
            throw new \InvalidArgumentException('Invalid route handler.');
        }
        $prefix = implode('/', array_column($this->groups, 'prefix'));
        $path = trim($prefix . '/' . trim($path, '/'), '/');
        $expression = $this->compilePattern($path, '[^/]+');
        foreach ($this->frameworkRoutes as $existing) {
            if ($existing->path() === $path && array_intersect($methods, $existing->methods())) {
                throw new \InvalidArgumentException('Duplicate route paths and methods are not allowed.');
            }
        }
        $route = new Route(array_values(array_unique($methods)), $path, $handler, $expression, function (Route $current, string $name): void {
            foreach ($this->frameworkRoutes as $existing) {
                if ($existing !== $current && $existing->routeName() === $name) {
                    throw new \InvalidArgumentException('Route names must be unique.');
                }
            }
        });
        foreach ($this->groups as $group) {
            $route->middleware(...$group['middleware']);
        }
        $this->frameworkRoutes[] = $route;
        return $route;
    }

    public function get(string $path, mixed $handler): Route { return $this->map(['GET', 'HEAD'], $path, $handler); }
    public function post(string $path, mixed $handler): Route { return $this->map('POST', $path, $handler); }
    public function put(string $path, mixed $handler): Route { return $this->map('PUT', $path, $handler); }
    public function patch(string $path, mixed $handler): Route { return $this->map('PATCH', $path, $handler); }
    public function delete(string $path, mixed $handler): Route { return $this->map('DELETE', $path, $handler); }
    public function options(string $path, mixed $handler): Route { return $this->map('OPTIONS', $path, $handler); }

    public function registeredRoutes(): array
    {
        return $this->frameworkRoutes;
    }

    public function group(string $prefix, array $middleware, callable $register): void
    {
        $this->groups[] = ['prefix' => trim($prefix, '/'), 'middleware' => $middleware];
        try {
            $register($this);
        } finally {
            array_pop($this->groups);
        }
    }

    public function url(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->namedRoute($name);
        $used = [];
        $path = preg_replace_callback('/\{([a-z][a-z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/i', static function (array $match) use ($parameters, &$used): string {
            if (!array_key_exists($match[1], $parameters) || !is_scalar($parameters[$match[1]])
                || in_array((string) $parameters[$match[1]], ['.', '..'], true)
                || preg_match('/[\x00-\x1f\x7f\/\\\\]/', (string) $parameters[$match[1]])) {
                throw new \InvalidArgumentException('Missing or invalid route parameter.');
            }
            $used[] = $match[1];
            return rawurlencode((string) $parameters[$match[1]]);
        }, $route->path());
        if (array_diff(array_keys($parameters), $used)) {
            throw new \InvalidArgumentException('Unexpected route parameter.');
        }
        if (!preg_match($route->expression(), $path)) {
            throw new \InvalidArgumentException('Generated URL does not match its route.');
        }
        return '/' . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function integrityToken(string $name, array $immutable, array $parameters = [], string $method = 'POST', int $ttl = 900): string
    {
        $route = $this->namedRoute($name);
        if (!in_array($method, $route->methods(), true) || $route->isStateless()) {
            throw new \InvalidArgumentException('Integrity challenges require a matching session route.');
        }
        $this->url($name, $parameters);
        return DataIntegrity::issue($route->scope(), $immutable, $method, $parameters, $ttl);
    }

    public function handle(Request $request): Response
    {
        $this->container ??= new Container();
        $path = trim($request->path(), '/');
        $allowed = [];
        foreach ($this->frameworkRoutes as $route) {
            if (!preg_match($route->expression(), $path, $matches)) {
                continue;
            }
            if (!in_array($request->method(), $route->methods(), true)) {
                array_push($allowed, ...$route->methods());
                continue;
            }
            $parameters = [];
            foreach ($matches as $name => $value) {
                if (is_string($name)) {
                    $decoded = rawurldecode($value);
                    if (in_array($decoded, ['.', '..'], true) || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $decoded)) {
                        throw new HttpException(400, 'Invalid route parameter.');
                    }
                    $parameters[$name] = $decoded;
                }
            }
            $request = $request->withAttribute('route', $route)->withAttribute('route.parameters', $parameters);
            return (new Pipeline($route->middlewareList(), $this->container))->handle($request, function (Request $current) use ($route, $parameters): Response {
                $handler = $route->handler();
                if (is_array($handler) && is_subclass_of($handler[0], Controller::class)) {
                    $controller = $handler[0] instanceof Controller ? $handler[0] : $this->makeController($handler[0], $current, $parameters);
                    $controller->attachContext($current, $this->container, $parameters);
                    $action = str_ends_with($handler[1], 'Action') ? substr($handler[1], 0, -6) : $handler[1];
                    return $this->capture(fn() => $controller->executeAction($action, $current->guard(), $route->requestPolicy(), $route->isStateless()));
                }
                $policy = $route->requestPolicy() ?? [];
                $input = $current->guard()->validate($route->scope(), $parameters, $policy, $route->isStateless(), $current->attribute('iris.authenticated') === true);
                $current = $current->withAttribute('iris.input', $input);
                return $this->container->withInstance(Request::class, $current, function () use ($handler, $current, $parameters): Response {
                    if (is_array($handler) && is_string($handler[0])) {
                        $handler[0] = $this->container->get($handler[0]);
                    } elseif (is_string($handler) && class_exists($handler)) {
                        $handler = $this->container->get($handler);
                    }
                    return $this->capture(fn() => Invoker::invoke($handler, $current, $parameters, $this->container));
                });
            });
        }
        if ($allowed !== []) {
            return Response::text('Method not allowed.', 405, ['Allow' => implode(', ', array_unique($allowed))]);
        }
        if ($this->match($path)) {
            if (!isset($this->params['controller'], $this->params['action'])
                || !is_string($this->params['controller']) || !is_string($this->params['action'])
                || !preg_match('/^[a-z][a-z0-9-]*$/iD', $this->params['controller'])
                || !preg_match('/^[a-z][a-z0-9-]*$/iD', $this->params['action'])) {
                throw new HttpException(404, 'Page not found.');
            }
            $controller = $this->getNamespace() . $this->convertToStudlyCaps($this->params['controller'] ?? '');
            $action = $this->convertToCamelCase($this->params['action'] ?? '');
            if (!is_subclass_of($controller, Controller::class) || !(new \ReflectionClass($controller))->isInstantiable()) {
                throw new HttpException(404, 'Controller not found.');
            }
            return $this->container->withInstance(Request::class, $request, function () use ($controller, $action, $request): Response {
                $instance = $this->makeController($controller, $request, $this->params, false);
                return $this->capture(fn() => $instance->executeAction($action, $request->guard()));
            });
        }
        throw new HttpException(404, 'Page not found.');
    }

    private function capture(callable $operation): Response
    {
        ob_start();
        try {
            $result = $operation();
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if ($result instanceof Response) {
            if ($output !== '') {
                throw new \LogicException('Return a response or emit output, not both.');
            }
            return $result;
        }
        if ($result !== null) {
            throw new \LogicException('Route handlers must return a Response or null.');
        }
        return Response::html($output);
    }

    private function makeController(string $class, Request $request, array $parameters, bool $routeValuesOnly = true): Controller
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $overrides = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === 'route_params') {
                $overrides['route_params'] = $parameters;
            }
        }
        $controller = $this->container->make($class, $overrides);
        $controller->attachContext($request, $this->container, $parameters, $routeValuesOnly);
        return $controller;
    }

    private function namedRoute(string $name): Route
    {
        $matches = array_values(array_filter($this->frameworkRoutes, static fn(Route $route): bool => $route->routeName() === $name));
        if (count($matches) !== 1) {
            throw new \InvalidArgumentException('Named routes must exist and be unique.');
        }
        return $matches[0];
    }
}
