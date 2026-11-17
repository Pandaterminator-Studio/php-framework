<?php

namespace Core\Middleware;

use Core\Container;
use Core\Http\Request;
use Core\Http\Response;

final class Pipeline
{
    private array $middleware;

    public function __construct(array $middleware = [], private ?Container $container = null)
    {
        foreach ($middleware as $handler) {
            if (!($handler instanceof MiddlewareInterface) && !is_callable($handler) && !($container !== null && is_string($handler))) {
                throw new \InvalidArgumentException('Middleware must implement MiddlewareInterface or be callable.');
            }
        }
        $this->middleware = array_values($middleware);
    }

    public function handle(Request $request, callable $destination): Response
    {
        $next = $this->step($destination);
        foreach (array_reverse($this->middleware) as $handler) {
            $next = $this->step(function (Request $request) use ($handler, $next) {
                if (is_string($handler) && $this->container !== null) {
                    $handler = $this->container->get($handler);
                }
                return $handler instanceof MiddlewareInterface ? $handler->process($request, $next) : $handler($request, $next);
            });
        }
        return $next($request);
    }

    private function step(callable $operation): callable
    {
        return function (Request $request) use ($operation): Response {
            $response = $this->container === null
                ? $operation($request)
                : $this->container->withInstance(Request::class, $request, fn() => $operation($request));
            if (!$response instanceof Response) {
                throw new \UnexpectedValueException('The request pipeline must return a Response.');
            }
            return $response;
        };
    }
}
