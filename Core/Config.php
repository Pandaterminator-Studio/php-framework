<?php

namespace Core;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

final class Config
{
    private array $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (self::segments($key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function has(string $key): bool
    {
        $value = $this->values;
        foreach (self::segments($key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }
        return true;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = self::segments($key);
        $last = array_pop($segments);
        $target = &$this->values;
        foreach ($segments as $segment) {
            if (array_key_exists($segment, $target) && !is_array($target[$segment])) {
                throw new InvalidArgumentException('Cannot set a nested configuration value through a scalar.');
            }
            if (!array_key_exists($segment, $target)) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target[$last] = $value;
    }

    public function merge(array $values): void
    {
        $this->values = self::mergeValues($this->values, $values);
    }

    public static function fromDirectory(string $directory): self
    {
        if ($directory === '' || str_contains($directory, "\0") || !is_dir($directory) || !is_readable($directory)) {
            throw new InvalidArgumentException('Configuration directory must be readable.');
        }
        $root = realpath($directory);
        $entries = scandir($directory);
        if ($root === false || $entries === false) {
            throw new RuntimeException('Configuration directory could not be read.');
        }
        $values = [];
        foreach ($entries as $entry) {
            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            $name = pathinfo($entry, PATHINFO_FILENAME);
            if (is_link($path) || !is_file($path) || !is_readable($path)
                || preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $name) !== 1) {
                throw new InvalidArgumentException('Configuration files must be readable regular PHP files with valid names.');
            }
            $loaded = (static function (string $file): mixed {
                return require $file;
            })($path);
            if (!is_array($loaded)) {
                throw new UnexpectedValueException('Configuration files must return arrays.');
            }
            $values[$name] = $loaded;
        }
        return new self($values);
    }

    public static function env(string $name, mixed $default = null): mixed
    {
        self::environmentName($name);
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }
        $literal = strtolower($value);
        if ($literal === 'true' || $literal === 'false' || $literal === 'null') {
            return match ($literal) {
                'true' => true,
                'false' => false,
                'null' => null,
            };
        }
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                return $integer;
            }
        }
        return $value;
    }

    public static function loadEnv(string $path, bool $override = false): void
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Environment file must be a readable regular file.');
        }
        $contents = file_get_contents($path, false, null, 0, 1048577);
        if ($contents === false || strlen($contents) > 1048576 || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new InvalidArgumentException('Environment file must contain at most 1 MiB of UTF-8 text.');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        $values = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                throw new InvalidArgumentException('Malformed environment file assignment.');
            }
            $name = trim($parts[0]);
            self::environmentName($name);
            if (array_key_exists($name, $values)) {
                throw new InvalidArgumentException('Duplicate environment file assignment.');
            }
            $value = trim($parts[1]);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if (strlen($value) < 2 || substr($value, -1) !== $quote || str_contains(substr($value, 1, -1), $quote)) {
                    throw new InvalidArgumentException('Malformed quoted environment value.');
                }
                $value = substr($value, 1, -1);
            }
            $values[$name] = $value;
        }
        foreach ($values as $name => $value) {
            if (!$override && (getenv($name) !== false || array_key_exists($name, $_ENV))) {
                continue;
            }
            if (!putenv($name . '=' . $value)) {
                throw new RuntimeException('An environment value could not be loaded.');
            }
            $_ENV[$name] = $value;
        }
    }

    private static function segments(string $key): array
    {
        if (preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $key) !== 1) {
            throw new InvalidArgumentException('Configuration keys must contain nonempty dot-separated names.');
        }
        return explode('.', $key);
    }

    private static function environmentName(string $name): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid environment variable name.');
        }
    }

    private static function mergeValues(array $original, array $incoming): array
    {
        if (array_is_list($original) || array_is_list($incoming)) {
            return $incoming;
        }
        foreach ($incoming as $key => $value) {
            $original[$key] = isset($original[$key]) && is_array($original[$key]) && is_array($value)
                ? self::mergeValues($original[$key], $value) : $value;
        }
        return $original;
    }
}
