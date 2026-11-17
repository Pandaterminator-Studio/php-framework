<?php

namespace Core\Middleware;

use Core\Cache\CacheInterface;
use Core\Http\Request;
use Core\Http\Response;

final class RateLimit implements MiddlewareInterface
{
    private ?\Closure $keyResolver;

    public function __construct(private CacheInterface $cache, private int $limit, private int $windowSeconds, ?callable $keyResolver = null)
    {
        if ($limit < 1 || $windowSeconds < 1 || $windowSeconds > 86400) {
            throw new \InvalidArgumentException('Rate limits require a positive limit and a window of 1 to 86400 seconds.');
        }
        $this->keyResolver = $keyResolver === null ? null : \Closure::fromCallable($keyResolver);
    }

    public function process(Request $request, callable $next): Response
    {
        $now = time();
        $bucket = intdiv($now, $this->windowSeconds);
        $reset = ($bucket + 1) * $this->windowSeconds;
        if ($this->keyResolver !== null) {
            $identity = ($this->keyResolver)($request);
            if (!is_string($identity) || $identity === '' || strlen($identity) > 4096) {
                throw new \InvalidArgumentException('Rate limit key resolvers must return a nonempty string of at most 4096 bytes.');
            }
        } else {
            $address = $request->server('REMOTE_ADDR', 'unknown');
            $address = is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : 'unknown';
            $path = '/' . trim(strtolower(rawurldecode($request->path())), '/');
            $identity = $address . "\0" . $request->method() . "\0" . $path;
        }
        $key = 'rate:' . hash('sha256', $identity) . ':' . $bucket;
        $count = $this->cache->increment($key, 1, $reset - $now);
        $headers = [
            'X-RateLimit-Limit' => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) max(0, $this->limit - $count),
            'X-RateLimit-Reset' => (string) $reset,
        ];
        if ($count > $this->limit) {
            $headers['Retry-After'] = (string) max(1, $reset - $now);
            $headers['Cache-Control'] = 'no-store';
            return $request->expectsJson() ? Response::json(['error' => 'Too many requests.'], 429, $headers)
                : Response::text('Too many requests.', 429, $headers);
        }
        $response = $next($request);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
