<?php

namespace Core\Database;

use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use Throwable;

final class Connection
{
    private ?PDO $pdo = null;
    private int $depth = 0;
    private bool $broken = false;

    public function __construct(private array $config)
    {
    }

    public function driver(): string
    {
        $dsn = $this->config['dsn'] ?? null;
        if ($dsn !== null && !is_string($dsn)) {
            throw new InvalidArgumentException('The database DSN must be a string.');
        }
        $driver = $dsn === null ? ($this->config['driver'] ?? 'mysql') : strstr($dsn, ':', true);
        if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new InvalidArgumentException('Supported database drivers are mysql and sqlite.');
        }
        return $driver;
    }

    public function pdo(): PDO
    {
        if ($this->broken) {
            throw new LogicException('The current database transaction cannot continue.');
        }
        if ($this->pdo === null) {
            $driver = $this->driver();
            $options = $this->config['options'] ?? [];
            if (!is_array($options)) {
                throw new InvalidArgumentException('PDO options must be an array.');
            }
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
            $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
            $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
            $options[PDO::ATTR_PERSISTENT] = false;
            $this->pdo = new PDO($this->dsn($driver), $this->config['username'] ?? $this->config['user'] ?? null, $this->config['password'] ?? null, $options);
            if ($driver === 'sqlite') {
                $timeout = $this->config['busy_timeout'] ?? 5000;
                if (!is_int($timeout) || $timeout < 0) {
                    $this->pdo = null;
                    throw new InvalidArgumentException('SQLite busy_timeout must be a non-negative number of milliseconds.');
                }
                $this->pdo->exec('PRAGMA foreign_keys = ON');
                $this->pdo->exec('PRAGMA busy_timeout = ' . $timeout);
            }
        }
        return $this->pdo;
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings)->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo()->lastInsertId();
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    public function quoteIdentifier(string $identifier): string
    {
        $segments = explode('.', $identifier);
        $quote = $this->driver() === 'mysql' ? '`' : '"';
        foreach ($segments as &$segment) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $segment)) {
                throw new InvalidArgumentException('Invalid SQL identifier.');
            }
            $segment = $quote . $segment . $quote;
        }
        return implode('.', $segments);
    }

    public function transaction(callable $operation): mixed
    {
        $pdo = $this->pdo();
        $level = $this->depth;
        $savepoint = 'iris_transaction_' . $level;
        if ($level === 0) {
            if ($pdo->inTransaction()) {
                throw new LogicException('Use Connection::transaction to manage transaction boundaries.');
            }
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        $this->depth++;
        try {
            $result = $operation($this);
            if ($this->broken || !$pdo->inTransaction()) {
                throw new LogicException('The database transaction ended unexpectedly.');
            }
            if ($level === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (Throwable $exception) {
            try {
                if ($pdo->inTransaction()) {
                    if ($level === 0) {
                        $pdo->rollBack();
                    } else {
                        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    }
                }
            } catch (Throwable $rollbackException) {
                $this->broken = true;
            }
            throw $exception;
        } finally {
            $this->depth = $level;
            if ($level === 0 && $this->broken) {
                $this->pdo = null;
                $this->broken = false;
            }
        }
    }

    public function disconnect(): void
    {
        if ($this->depth > 0 || $this->pdo?->inTransaction()) {
            throw new LogicException('Cannot disconnect during a transaction.');
        }
        $this->pdo = null;
    }

    private function dsn(string $driver): string
    {
        if (isset($this->config['dsn'])) {
            if (!is_string($this->config['dsn']) || $this->config['dsn'] === $driver . ':') {
                throw new InvalidArgumentException('A database DSN must identify a database.');
            }
            return $this->config['dsn'];
        }
        $database = $this->config['database'] ?? $this->config['name'] ?? null;
        if (!is_string($database) || $database === '') {
            throw new InvalidArgumentException('A database name or SQLite path is required.');
        }
        if ($driver === 'sqlite') {
            return 'sqlite:' . $database;
        }
        $host = $this->config['host'] ?? '127.0.0.1';
        $charset = $this->config['charset'] ?? 'utf8mb4';
        $port = $this->config['port'] ?? 3306;
        if (!is_string($host) || $host === '' || strpbrk($host . $database, ";\0") !== false
            || !is_string($charset) || !preg_match('/^[a-zA-Z0-9_]+$/D', $charset)
            || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new InvalidArgumentException('Invalid MySQL connection settings.');
        }
        return 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=' . $charset;
    }

    private function statement(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $positional = array_is_list($bindings);
        $names = [];
        foreach ($bindings as $name => $value) {
            if ($positional) {
                $parameter = $name + 1;
            } else {
                if (!is_string($name) || !preg_match('/^:?[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
                    throw new InvalidArgumentException('Use a list for positional bindings or names for named bindings.');
                }
                $parameter = ':' . ltrim($name, ':');
                if (isset($names[$parameter])) {
                    throw new InvalidArgumentException('Duplicate named binding.');
                }
                $names[$parameter] = true;
            }
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                is_string($value), is_float($value) && is_finite($value) => PDO::PARAM_STR,
                default => throw new InvalidArgumentException('Database bindings must be finite scalar values or null.'),
            };
            $statement->bindValue($parameter, is_float($value) ? (string) $value : $value, $type);
        }
        $statement->execute();
        return $statement;
    }
}
