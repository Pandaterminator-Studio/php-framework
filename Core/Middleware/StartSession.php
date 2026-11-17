<?php

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Session;

final class StartSession implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        Session::start();
        return $next($request);
    }
}
