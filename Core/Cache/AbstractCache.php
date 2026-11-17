<?php

namespace Core\Cache;

abstract class AbstractCache implements CacheInterface
{
    public const MAX_VALUE_BYTES = 65536;
    protected int $maxEntries;
    private \Closure $clock;

    public function __construct(int $maxEntries = 1024, ?callable $clock = null)
    {
        if ($maxEntries < 1 || $maxEntries > 10000) {
            throw new \InvalidArgumentException('Cache capacity must be between 1 and 10000 entries.');
        }
        $this->maxEntries = $maxEntries;
        $this->clock = $clock === null ? static fn(): int => time() : \Closure::fromCallable($clock);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $hash = $this->hash($key);
        return $this->transaction(function (array &$entries) use ($hash, $default): mixed {
            $this->prune($entries);
            return array_key_exists($hash, $entries) ? $entries[$hash]['value'] : $default;
        });
    }

    public function has(string $key): bool
    {
        $hash = $this->hash($key);
        return $this->transaction(function (array &$entries) use ($hash): bool {
            $this->prune($entries);
            return array_key_exists($hash, $entries);
        });
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $hash = $this->hash($key);
        $expires = $this->expiration($ttl);
        $this->validateValue($value);
        return $this->transaction(function (array &$entries) use ($hash, $value, $expires, $ttl): bool {
            $this->prune($entries);
            unset($entries[$hash]);
            if ($ttl !== 0) {
                $entries[$hash] = ['value' => $value, 'expires' => $expires];
                $this->bound($entries);
            }
            return true;
        });
    }

    public function delete(string $key): bool
    {
        $hash = $this->hash($key);
        return $this->transaction(static function (array &$entries) use ($hash): bool {
            unset($entries[$hash]);
            return true;
        });
    }

    public function clear(): bool
    {
        return $this->transaction(static function (array &$entries): bool {
            $entries = [];
            return true;
        });
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $hash = $this->hash($key);
        $expires = $this->expiration($ttl);
        $this->validateValue($value);
        return $this->transaction(function (array &$entries) use ($hash, $value, $expires, $ttl): bool {
            $this->prune($entries);
            if (array_key_exists($hash, $entries) || $ttl === 0) {
                return false;
            }
            $entries[$hash] = ['value' => $value, 'expires' => $expires];
            $this->bound($entries);
            return true;
        });
    }

    public function increment(string $key, int $amount = 1, ?int $ttl = null): int
    {
        $hash = $this->hash($key);
        $expires = $this->expiration($ttl);
        return $this->transaction(function (array &$entries) use ($hash, $amount, $expires, $ttl): int {
            $this->prune($entries);
            $existing = array_key_exists($hash, $entries);
            $current = $existing ? $entries[$hash]['value'] : 0;
            if (!is_int($current)) {
                throw new \UnexpectedValueException('Only integer cache entries can be incremented.');
            }
            $value = $current + $amount;
            if (!is_int($value)) {
                throw new \OverflowException('Cache counter overflow.');
            }
            if ($existing) {
                $entries[$hash]['value'] = $value;
            } elseif ($ttl !== 0) {
                $entries[$hash] = ['value' => $value, 'expires' => $expires];
                $this->bound($entries);
            }
            return $value;
        });
    }

    abstract protected function transaction(callable $operation): mixed;

    protected function validateValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 24 || is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('Cache values must contain finite JSON-compatible data.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateValue($item, $depth + 1);
            }
        }
        if ($depth === 0) {
            try {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 32);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('Cache values must contain valid JSON-compatible data.', 0, $exception);
            }
            if (strlen($encoded) > self::MAX_VALUE_BYTES) {
                throw new \InvalidArgumentException('Cache values must not exceed 64 KiB.');
            }
        }
    }

    private function hash(string $key): string
    {
        if ($key === '' || strlen($key) > 1024) {
            throw new \InvalidArgumentException('Cache keys must contain between 1 and 1024 bytes.');
        }
        return hash('sha256', $key);
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) {
            throw new \UnexpectedValueException('Cache clocks must return nonnegative integer timestamps.');
        }
        return $now;
    }

    private function expiration(?int $ttl): ?int
    {
        if ($ttl !== null && ($ttl < 0 || $ttl > PHP_INT_MAX - $this->now())) {
            throw new \InvalidArgumentException('Cache TTL must be a nonnegative number of seconds or null.');
        }
        return $ttl === null ? null : $this->now() + $ttl;
    }

    private function prune(array &$entries): void
    {
        $now = $this->now();
        foreach ($entries as $hash => $entry) {
            if ($entry['expires'] !== null && $entry['expires'] <= $now) {
                unset($entries[$hash]);
            }
        }
    }

    private function bound(array &$entries): void
    {
        while (count($entries) > $this->maxEntries) {
            unset($entries[array_key_first($entries)]);
        }
    }
}
