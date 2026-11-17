<?php

namespace Core\Queue;

use Closure;
use Core\Database\Connection;
use InvalidArgumentException;
use JsonException;
use LogicException;
use UnexpectedValueException;

final class DatabaseQueue
{
    private const MAX_PAYLOAD_BYTES = 65536;
    private const MAX_DEPTH = 32;
    public const MAX_DELAY = 31536000;
    public const MAX_LEASE = 86400;

    public function __construct(private Connection $connection, private ?Closure $clock = null)
    {
    }

    public static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $name) === 1;
    }

    public function install(): void
    {
        $this->requireOutsideTransaction();
        $mysql = $this->connection->driver() === 'mysql';
        $idType = $mysql ? 'VARBINARY(32)' : 'TEXT';
        $nameType = $mysql ? 'VARBINARY(128)' : 'TEXT';
        $tokenType = $mysql ? 'VARBINARY(64)' : 'TEXT';
        $payloadType = $mysql ? 'MEDIUMBLOB' : 'TEXT';
        $indexes = $mysql ? ', INDEX iris_jobs_ready (failed_at, available_at, id)' : '';
        $engine = $mysql ? ' ENGINE=InnoDB' : '';
        $this->connection->execute("CREATE TABLE IF NOT EXISTS iris_jobs (
            id $idType NOT NULL PRIMARY KEY,
            name $nameType NOT NULL,
            payload $payloadType NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL,
            available_at BIGINT NOT NULL,
            lease_token $tokenType NULL,
            failed_at BIGINT NULL
            $indexes
        )$engine");
        if (!$mysql) {
            $this->connection->execute('CREATE INDEX IF NOT EXISTS iris_jobs_ready ON iris_jobs (failed_at, available_at, id)');
        }
    }

    public function push(string $name, array $payload = [], int $delay = 0, int $maxAttempts = 3): string
    {
        if (!self::validName($name)) {
            throw new InvalidArgumentException('Invalid queue job name.');
        }
        $this->validateDelay($delay);
        if ($maxAttempts < 1 || $maxAttempts > 100) {
            throw new InvalidArgumentException('Maximum attempts must be between 1 and 100.');
        }
        $encoded = $this->encodePayload($payload);
        $id = bin2hex(random_bytes(16));
        $this->connection->execute(
            'INSERT INTO iris_jobs (id, name, payload, max_attempts, available_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $name, $encoded, $maxAttempts, $this->now() + $delay],
        );
        return $id;
    }

    public function reserve(int $leaseSeconds = 60): ?ReservedJob
    {
        if ($leaseSeconds < 1 || $leaseSeconds > self::MAX_LEASE) {
            throw new InvalidArgumentException('Lease duration must be between 1 and 86400 seconds.');
        }
        $this->requireOutsideTransaction();
        do {
            $job = $this->locked(function () use ($leaseSeconds): ReservedJob|false|null {
                $row = $this->firstLocked(
                    'SELECT id, name, payload, attempts, max_attempts FROM iris_jobs
                     WHERE failed_at IS NULL AND available_at <= ? AND attempts < max_attempts
                     ORDER BY available_at, id LIMIT 1',
                    [$this->now()],
                );
                if ($row === null) {
                    return null;
                }
                $now = $this->now();
                $payload = $this->decodePayload($row['payload']);
                if ($payload === null) {
                    $this->connection->execute('UPDATE iris_jobs SET failed_at = ?, lease_token = NULL WHERE id = ?', [$now, $row['id']]);
                    return false;
                }
                $token = bin2hex(random_bytes(32));
                $this->connection->execute(
                    'UPDATE iris_jobs SET attempts = attempts + 1, lease_token = ?, available_at = ? WHERE id = ?',
                    [$token, $now + $leaseSeconds, $row['id']],
                );
                return new ReservedJob($row['id'], $row['name'], $payload, (int) $row['attempts'] + 1, (int) $row['max_attempts'], $token);
            });
        } while ($job === false);
        return $job;
    }

    public function complete(ReservedJob $job): bool
    {
        return $this->settle($job, null);
    }

    public function release(ReservedJob $job, int $delay = 0): bool
    {
        $this->validateDelay($delay);
        return $this->settle($job, $delay);
    }

    public function failed(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Failed job limit must be between 1 and 100.');
        }
        return $this->connection->select(
            'SELECT id, name, attempts FROM iris_jobs
             WHERE failed_at IS NOT NULL OR (attempts >= max_attempts AND available_at <= ?)
             ORDER BY COALESCE(failed_at, available_at) DESC, id LIMIT ' . $limit,
            [$this->now()],
        );
    }

    public function retry(string $id): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid queue job ID.');
        }
        $now = $this->now();
        return $this->connection->execute(
            'UPDATE iris_jobs SET attempts = 0, failed_at = NULL, lease_token = NULL, available_at = ?
             WHERE id = ? AND (failed_at IS NOT NULL OR (attempts >= max_attempts AND available_at <= ?))',
            [$now, $id, $now],
        ) === 1;
    }

    private function settle(ReservedJob $job, ?int $delay): bool
    {
        return $this->locked(function () use ($job, $delay): bool {
            $row = $this->firstLocked(
                'SELECT attempts, max_attempts, available_at FROM iris_jobs WHERE id = ? AND lease_token = ? AND failed_at IS NULL',
                [$job->id, $job->token],
            );
            $now = $this->now();
            if ($row === null || (int) $row['available_at'] <= $now) {
                return false;
            }
            if ($delay === null) {
                $this->connection->execute('DELETE FROM iris_jobs WHERE id = ?', [$job->id]);
            } else {
                $failedAt = (int) $row['attempts'] >= (int) $row['max_attempts'] ? $now : null;
                $this->connection->execute(
                    'UPDATE iris_jobs SET lease_token = NULL, failed_at = ?, available_at = ? WHERE id = ?',
                    [$failedAt, $now + $delay, $job->id],
                );
            }
            return true;
        });
    }

    private function locked(callable $operation): mixed
    {
        return $this->connection->transaction(function () use ($operation): mixed {
            if ($this->connection->driver() === 'sqlite') {
                $this->connection->execute('UPDATE iris_jobs SET attempts = attempts WHERE 0');
            }
            return $operation();
        });
    }

    private function firstLocked(string $sql, array $bindings): ?array
    {
        $lock = $this->connection->driver() === 'mysql' ? ' FOR UPDATE' : '';
        return $this->connection->first($sql . $lock, $bindings);
    }

    private function requireOutsideTransaction(): void
    {
        if ($this->connection->pdo()->inTransaction()) {
            throw new LogicException('Run queue installation and reservation outside an existing transaction.');
        }
    }

    private function now(): int
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - self::MAX_DELAY) {
            throw new UnexpectedValueException('The queue clock must return a supported Unix timestamp.');
        }
        return $now;
    }

    private function validateDelay(int $delay): void
    {
        if ($delay < 0 || $delay > self::MAX_DELAY) {
            throw new InvalidArgumentException('Job delay must be between 0 and 31536000 seconds.');
        }
    }

    private function encodePayload(array $payload): string
    {
        $budget = self::MAX_PAYLOAD_BYTES;
        $this->checkValue($payload, 0, $budget);
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, self::MAX_DEPTH);
        } catch (JsonException) {
            throw new InvalidArgumentException('Queue payload must contain valid JSON data.');
        }
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Queue payload exceeds 64 KiB.');
        }
        return $json;
    }

    private function checkValue(mixed $value, int $depth, int &$budget): void
    {
        $budget -= is_string($value) ? strlen($value) + 1 : 1;
        if ($budget < 0 || $depth > self::MAX_DEPTH || is_object($value) || is_resource($value)
            || is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Queue payload must contain bounded JSON data.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $budget -= strlen($key);
                }
                $this->checkValue($item, $depth + 1, $budget);
            }
        }
    }

    private function decodePayload(string $payload): ?array
    {
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            return null;
        }
        try {
            $data = json_decode($payload, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                return null;
            }
            $budget = self::MAX_PAYLOAD_BYTES;
            $this->checkValue($data, 0, $budget);
            return $data;
        } catch (JsonException|InvalidArgumentException) {
            return null;
        }
    }
}
