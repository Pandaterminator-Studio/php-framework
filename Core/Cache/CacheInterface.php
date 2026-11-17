<?php

namespace Core\Cache;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function clear(): bool;
    public function add(string $key, mixed $value, ?int $ttl = null): bool;
    public function increment(string $key, int $amount = 1, ?int $ttl = null): int;
}
