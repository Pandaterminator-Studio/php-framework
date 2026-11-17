<?php

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\HttpException;

final class Authenticate implements MiddlewareInterface
{
    private \Closure $resolver;

    public function __construct(callable $resolver)
    {
        $this->resolver = \Closure::fromCallable($resolver);
    }

    public function process(Request $request, callable $next): Response
    {
        $principal = ($this->resolver)($request);
        if ($principal === null || $principal === false) {
            throw new HttpException(401, 'Authentication required.');
        }
        return $next($request->withAttribute('user', $principal)->withAttribute('iris.authenticated', true));
    }
}
