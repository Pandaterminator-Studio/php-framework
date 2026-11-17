<?php

namespace Core;

use InvalidArgumentException;

final class DataIntegrity
{
    private const SESSION_KEY = '_iris_integrity';
    private const MAX_PENDING = 64;

    public static function issue(string $scope, array $immutable, string $method = 'POST', array $routeValues = [], int $ttl = 900): string
    {
        if ($scope === '' || $ttl < 1 || $ttl > 3600 || count($immutable) > 64) {
            throw new InvalidArgumentException('Invalid integrity challenge configuration.');
        }
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported integrity challenge method.');
        }
        $values = self::normalize($immutable);
        $route = self::normalize($routeValues);
        foreach ($route as $name => $value) {
            if (array_key_exists($name, $values) && $values[$name] !== $value) {
                throw new InvalidArgumentException('Protected values must match their route values.');
            }
        }
        Session::start();
        self::prune();
        while (count($_SESSION[self::SESSION_KEY]) >= self::MAX_PENDING) {
            unset($_SESSION[self::SESSION_KEY][array_key_first($_SESSION[self::SESSION_KEY])]);
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY][$token] = [
            'scope' => $scope,
            'method' => $method,
            'route' => $route,
            'values' => $values,
            'trusted' => $immutable,
            'expires' => time() + $ttl,
        ];
        return $token;
    }

    public static function verify($token, string $scope, string $method, array $routeValues, array $input, array $editable, ?array $protected = null): array
    {
        Session::start();
        self::prune();
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HttpException(403, 'Missing or invalid data integrity token.');
        }
        $challenge = $_SESSION[self::SESSION_KEY][$token] ?? null;
        if (!is_array($challenge) || $challenge['scope'] !== $scope || $challenge['method'] !== $method
            || $challenge['route'] !== self::normalize($routeValues)) {
            throw new HttpException(403, 'Invalid, expired or already used data integrity token.');
        }
        if (array_intersect(array_keys($challenge['values']), $editable)) {
            throw new InvalidArgumentException('Protected fields cannot also be editable.');
        }
        if ($protected !== null && (array_diff($protected, array_keys($challenge['values']))
            || array_diff(array_keys($challenge['values']), $protected))) {
            throw new HttpException(403, 'Data integrity token does not match this action policy.');
        }
        $allowed = array_merge(array_keys($challenge['values']), $editable);
        foreach ($input as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new HttpException(400, 'Unexpected request field.');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new HttpException(400, 'Request fields must be scalar values.');
            }
        }
        foreach ($challenge['values'] as $name => $expected) {
            if (!array_key_exists($name, $input) || (!is_string($input[$name]) && !is_int($input[$name]) && !is_bool($input[$name]))
                || !hash_equals($expected, self::scalar($input[$name]))) {
                throw new HttpException(403, 'Protected request data was changed or removed.');
            }
        }
        $validated = array_intersect_key($input, array_flip($editable));
        unset($_SESSION[self::SESSION_KEY][$token]);
        return array_merge($validated, $challenge['trusted']);
    }

    private static function normalize(array $values): array
    {
        $normalized = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name)
                || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                throw new InvalidArgumentException('Protected values require named string, integer or boolean fields. Use integer cents or decimal strings for prices.');
            }
            $normalized[$name] = self::scalar($value);
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    public static function assertCurrent(array $verified, array $current, array $protected): void
    {
        $keys = array_flip($protected);
        $expected = self::normalize(array_intersect_key($verified, $keys));
        $actual = self::normalize(array_intersect_key($current, $keys));
        if (count($expected) !== count($keys) || count($actual) !== count($keys) || $expected !== $actual) {
            throw new HttpException(409, 'Protected data changed on the server. Reload before trying again.');
        }
    }

    private static function scalar($value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    private static function prune(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        foreach ($_SESSION[self::SESSION_KEY] as $token => $challenge) {
            if (!is_array($challenge) || ($challenge['expires'] ?? 0) <= time()) {
                unset($_SESSION[self::SESSION_KEY][$token]);
            }
        }
    }
}
