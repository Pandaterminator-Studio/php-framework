<?php

namespace Core;

use ReflectionMethod;
use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Invoker;
use Core\Routing\Route;
use Core\View\Renderer;
use Core\Validation\Validator;

abstract class Controller
{
    protected array $route_params = [];
    private array $validatedInput = [];
    private ?Request $currentRequest = null;
    private ?Container $services = null;
    private bool $routeValuesOnly = false;

    public function __construct($route_params){
        $this->route_params = $route_params;
    }

    final public function executeAction(string $action, RequestGuard $guard, ?array $policy = null, bool $stateless = false): ?Response
    {
        if (!preg_match('/^[a-z][a-zA-Z0-9]*$/D', $action)) {
            throw new HttpException(404, 'Action not found.');
        }
        $method = $action . 'Action';
        if (!method_exists($this, $method)) {
            throw new HttpException(404, 'Action not found.');
        }
        $reflection = new ReflectionMethod($this, $method);
        if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->getDeclaringClass()->getName() === self::class) {
            throw new HttpException(404, 'Action not found.');
        }
        $action = strtolower($action);
        $route = $this->currentRequest?->attribute('route');
        $scope = $route instanceof Route ? $route->scope() : static::class . ':' . $action;
        $this->validatedInput = $guard->validate($scope, $this->routeValues(), $policy ?? $this->requestPolicies()[$action] ?? [], $stateless, $this->currentRequest?->attribute('iris.authenticated') === true);
        $before = $this->before();
        if ($before instanceof Response) {
            return $before;
        }
        if ($before !== false) {
            $result = $this->currentRequest !== null && $this->services !== null
                ? Invoker::invoke([$this, $method], $this->currentRequest, $this->routeValues(), $this->services)
                : $this->$method();
            $this->after();
            if ($result !== null && !$result instanceof Response) {
                throw new \LogicException('Controller actions must return a Response or null.');
            }
            return $result;
        }
        return null;
    }

    final public function attachContext(Request $request, Container $container, array $parameters = [], bool $routeValuesOnly = true): void
    {
        $this->currentRequest = $request;
        $this->services = $container;
        $this->route_params = $parameters;
        $this->routeValuesOnly = $routeValuesOnly;
    }

    protected function request(): Request
    {
        if ($this->currentRequest === null) {
            throw new \LogicException('HTTP request context is unavailable outside the application kernel.');
        }
        return $this->currentRequest;
    }

    protected function validate(array $rules): array
    {
        return Validator::validate($this->input(), $rules);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        $renderer = $this->services?->get(Renderer::class) ?? new Renderer(dirname(__DIR__) . '/App/Views');
        return $renderer->response($template, $data, $status);
    }

    protected function redirect(string $location, int $status = 302): Response
    {
        return Response::redirect($location, $status);
    }

    protected function input(): array
    {
        return $this->validatedInput;
    }

    protected function integrityToken(string $action, array $immutable, string $method = 'POST', ?array $routeValues = null, int $ttl = 900): string
    {
        if ($this->currentRequest?->attribute('route') instanceof Route) {
            return $this->routeIntegrityToken($action, $immutable, $routeValues, $method, $ttl);
        }
        return DataIntegrity::issue(static::class . ':' . strtolower($action), $immutable, $method, $routeValues ?? $this->routeValues(), $ttl);
    }

    protected function routeIntegrityToken(string $name, array $immutable, ?array $parameters = null, string $method = 'POST', int $ttl = 900): string
    {
        if ($this->services === null) {
            throw new \LogicException('Named challenges require application context.');
        }
        return $this->services->get(Router::class)->integrityToken($name, $immutable, $parameters ?? $this->routeValues(), $method, $ttl);
    }

    protected function requestPolicies(): array
    {
        return [];
    }

    private function routeValues(): array
    {
        return $this->routeValuesOnly ? $this->route_params : array_diff_key($this->route_params, array_flip(['controller', 'action', 'namespace']));
    }

    protected function after()
    {

    }

    protected function before()
    {

    }
}
