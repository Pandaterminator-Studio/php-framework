<?php

namespace Core\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Stringable;
use Throwable;

final class JsonLogger extends AbstractLogger
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];
    private string $path;
    private int $minimum;

    public function __construct(string $path, string $minimumLevel = 'debug')
    {
        $this->minimum = $this->level($minimumLevel);
        if ($path === '' || str_contains($path, "\0") || is_link($path) || is_dir($path)) {
            throw new \InvalidArgumentException('Log output requires a regular file path.');
        }
        $directory = dirname($path);
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new \RuntimeException('Log storage could not be created.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_writable($resolved) || (fileperms($resolved) & 0077) !== 0) {
            throw new \RuntimeException('Log storage must be writable and private to its owner.');
        }
        $this->path = $resolved . DIRECTORY_SEPARATOR . basename($path);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->level($level) < $this->minimum) {
            return;
        }
        $context = $this->sanitize($context);
        $message = $message instanceof Throwable ? 'Exception ' . get_class($message) : (string) $message;
        $replacements = [];
        foreach ($context as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{' . $name . '}'] = $value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }
        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $this->text(strtr($message, $replacements)),
            'context' => $context,
        ];
        $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        if (is_link($this->path)) {
            throw new \RuntimeException('Log files must not be symlinks.');
        }
        $handle = fopen($this->path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('Log output could not be opened.');
        }
        try {
            if (!chmod($this->path, 0600) || !flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Log output could not be locked.');
            }
            $line = $encoded . "\n";
            $offset = 0;
            while ($offset < strlen($line)) {
                $written = fwrite($handle, substr($line, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Log output could not be written.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('Log output could not be flushed.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function level(mixed $level): int
    {
        if (!is_string($level) || !array_key_exists($level, self::LEVELS)) {
            throw new InvalidArgumentException('Unsupported log level.');
        }
        return self::LEVELS[$level];
    }

    private function sanitize(mixed $value, int $depth = 0, int &$budget = 65536): mixed
    {
        if ($depth > 8 || $budget <= 0) {
            return '[TRUNCATED]';
        }
        $budget -= 32;
        if ($value instanceof Throwable) {
            return ['type' => get_class($value), 'file' => basename($value->getFile()), 'line' => $value->getLine()];
        }
        if (is_object($value)) {
            return '[OBJECT ' . get_class($value) . ']';
        }
        if (is_resource($value)) {
            return '[RESOURCE]';
        }
        if (is_array($value)) {
            $sanitized = [];
            $count = 0;
            foreach ($value as $name => $entry) {
                if ($count++ >= 100 || $budget <= 0) {
                    $sanitized['_truncated'] = true;
                    break;
                }
                $outputName = is_string($name) ? substr($name, 0, 128) : $name;
                $budget -= is_string($outputName) ? strlen($outputName) : 8;
                $sanitized[$outputName] = is_string($name) && preg_match('/password|passwd|pwd|token|secret|authorization|cookie|key|credential|session/i', $name)
                    ? '[REDACTED]' : $this->sanitize($entry, $depth + 1, $budget);
            }
            return $sanitized;
        }
        if (is_float($value) && !is_finite($value)) {
            return '[NON-FINITE]';
        }
        if (is_string($value)) {
            $text = $this->text($value);
            $allowed = min(strlen($text), max(0, $budget));
            $budget -= $allowed;
            return $allowed < strlen($text) ? substr($text, 0, $allowed) . '[TRUNCATED]' : $text;
        }
        return $value;
    }

    private function text(string $value): string
    {
        $value = preg_replace('/\bBearer\s+[a-zA-Z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value);
        return strlen($value) > 4096 ? substr($value, 0, 4096) . '[TRUNCATED]' : $value;
    }
}
