<?php

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\HttpException;

final class Cors implements MiddlewareInterface
{
    public function __construct(private array $origins, private array $methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], private array $headers = ['Content-Type', 'Authorization'], private bool $credentials = false, private int $maxAge = 600)
    {
        if ($maxAge < 0 || $maxAge > 86400 || $origins === [] || $credentials && in_array('*', $origins, true)) {
            throw new \InvalidArgumentException('Invalid CORS configuration.');
        }
        foreach ($origins as $origin) {
            if (!is_string($origin) || $origin !== '*' && !self::validOrigin($origin)) {
                throw new \InvalidArgumentException('CORS origins must be explicit HTTP origins.');
            }
        }
        foreach ($methods as $method) {
            if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                throw new \InvalidArgumentException('Invalid CORS method.');
            }
        }
        foreach ($headers as $header) {
            if (!is_string($header) || !preg_match('/^[a-zA-Z0-9-]+$/D', $header)) {
                throw new \InvalidArgumentException('Invalid CORS header.');
            }
        }
    }

    public function process(Request $request, callable $next): Response
    {
        $origin = $request->header('Origin');
        if ($origin === null) {
            return $next($request);
        }
        if (!self::validOrigin($origin) || !in_array($origin, $this->origins, true) && !in_array('*', $this->origins, true)) {
            throw new HttpException(403, 'Origin is not allowed.');
        }
        $preflight = $request->method() === 'OPTIONS' && $request->header('Access-Control-Request-Method') !== null;
        if ($preflight) {
            $method = $request->header('Access-Control-Request-Method');
            $requested = trim($request->header('Access-Control-Request-Headers', ''));
            $headers = $requested === '' ? [] : array_map('trim', explode(',', $requested));
            if (!in_array($method, $this->methods, true) || array_diff(array_map('strtolower', $headers), array_map('strtolower', $this->headers))) {
                throw new HttpException(403, 'CORS preflight is not allowed.');
            }
            $response = new Response('', 204, [
                'Access-Control-Allow-Methods' => implode(', ', $this->methods),
                'Access-Control-Allow-Headers' => implode(', ', $this->headers),
                'Access-Control-Max-Age' => (string) $this->maxAge,
            ]);
        } else {
            $response = $next($request);
        }
        $vary = $response->header('Vary', '');
        $vary = is_array($vary) ? implode(', ', $vary) : $vary;
        $vary = array_values(array_unique(array_filter(array_map('trim', explode(',', $vary . ', Origin' . ($preflight ? ', Access-Control-Request-Method, Access-Control-Request-Headers' : ''))))));
        $response = $response->withHeader('Vary', implode(', ', $vary))->withHeader('Access-Control-Allow-Origin', in_array('*', $this->origins, true) ? '*' : $origin);
        return $this->credentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }

    private static function validOrigin(string $origin): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $origin)) {
            return false;
        }
        $parts = parse_url($origin);
        return $parts !== false && isset($parts['scheme'], $parts['host'])
            && in_array($parts['scheme'], ['http', 'https'], true)
            && !array_intersect(array_keys($parts), ['user', 'pass', 'path', 'query', 'fragment']);
    }
}
