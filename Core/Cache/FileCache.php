<?php

namespace Core\Cache;

final class FileCache extends AbstractCache
{
    public const MAX_STORE_BYTES = 8388608;
    private string $directory;

    public function __construct(string $directory, int $maxEntries = 1024, ?callable $clock = null)
    {
        parent::__construct($maxEntries, $clock);
        if ($directory === '' || str_contains($directory, "\0") || is_link($directory)) {
            throw new \InvalidArgumentException('Cache storage requires a private regular directory.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cache storage could not be created.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_writable($resolved) || ((fileperms($resolved) & 0077) !== 0)) {
            throw new \RuntimeException('Cache storage must be writable and private to its owner.');
        }
        $this->directory = $resolved;
    }

    protected function transaction(callable $operation): mixed
    {
        $lockPath = $this->directory . '/.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException('Cache lock files must not be symlinks.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Cache lock could not be opened.');
        }
        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cache storage could not be locked.');
            }
            $entries = $this->read();
            $original = $entries;
            $result = $operation($entries);
            if ($entries !== $original) {
                $this->write($entries);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(): array
    {
        $path = $this->directory . '/cache.json';
        if (is_link($path)) {
            throw new \RuntimeException('Cache data files must not be symlinks.');
        }
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path, false, null, 0, self::MAX_STORE_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_STORE_BYTES) {
            throw new \RuntimeException('Cache data exceeds its storage limit.');
        }
        try {
            $entries = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Cache data is malformed.', 0, $exception);
        }
        if (!is_array($entries) || count($entries) > 10000) {
            throw new \RuntimeException('Cache data has an invalid structure.');
        }
        foreach ($entries as $hash => $entry) {
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || !is_array($entry)
                || !array_key_exists('value', $entry) || !array_key_exists('expires', $entry)
                || ($entry['expires'] !== null && (!is_int($entry['expires']) || $entry['expires'] < 0))) {
                throw new \RuntimeException('Cache entries have an invalid structure.');
            }
            $this->validateValue($entry['value']);
        }
        while (count($entries) > $this->maxEntries) {
            unset($entries[array_key_first($entries)]);
        }
        return $entries;
    }

    private function write(array $entries): void
    {
        do {
            $raw = json_encode($entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 32);
            if (strlen($raw) <= self::MAX_STORE_BYTES) {
                break;
            }
            unset($entries[array_key_first($entries)]);
        } while ($entries !== []);
        $temporary = $this->directory . '/.write-' . bin2hex(random_bytes(12));
        try {
            if (file_put_contents($temporary, $raw, LOCK_EX) !== strlen($raw) || !chmod($temporary, 0600)
                || !rename($temporary, $this->directory . '/cache.json')) {
                throw new \RuntimeException('Cache data could not be saved.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
