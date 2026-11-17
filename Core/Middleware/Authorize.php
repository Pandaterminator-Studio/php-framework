<?php

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\HttpException;

final class Authorize implements MiddlewareInterface
{
    private \Closure $policy;

    public function __construct(callable $policy)
    {
        $this->policy = \Closure::fromCallable($policy);
    }

    public function process(Request $request, callable $next): Response
    {
        if (($this->policy)($request->attribute('user'), $request) !== true) {
            throw new HttpException(403, 'Access denied.');
        }
        return $next($request);
    }
}
