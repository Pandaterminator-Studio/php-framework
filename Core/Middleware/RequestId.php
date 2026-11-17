<?php

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;

final class RequestId implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $id = bin2hex(random_bytes(16));
        return $next($request->withAttribute('request_id', $id))->withHeader('X-Request-Id', $id);
    }
}
