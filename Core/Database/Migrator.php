<?php

namespace Core\Database;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class Migrator
{
    private string $directory;
    private string $table;
    private string $lockTable;
    private array $loaded = [];

    public function __construct(private Connection $connection, string $directory, string $table = 'iris_migrations')
    {
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
            throw new InvalidArgumentException('The migration directory must exist and be readable.');
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,47}$/D', $table)) {
            throw new InvalidArgumentException('Invalid migration table name.');
        }
        $this->directory = $resolved;
        $this->table = $connection->quoteIdentifier($table);
        $this->lockTable = $connection->quoteIdentifier($table . '_lock');
    }

    public function migrate(): array
    {
        return $this->locked(function (): array {
            $files = $this->files();
            $applied = $this->applied();
            if (array_diff(array_keys($applied), array_keys($files))) {
                throw new RuntimeException('An applied migration file is missing. Restore it before migrating.');
            }
            $pending = array_diff_key($files, $applied);
            if ($pending === []) {
                return [];
            }
            $batch = $applied === [] ? 1 : max(array_column($applied, 'batch')) + 1;
            $completed = [];
            foreach ($pending as $name => $file) {
                $this->load($name, $file)->up($this->connection);
                $this->connection->execute('INSERT INTO ' . $this->table . ' (migration, batch, applied_at) VALUES (:migration, :batch, :applied_at)', [
                    'migration' => $name,
                    'batch' => $batch,
                    'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                $completed[] = $name;
            }
            return $completed;
        });
    }

    public function rollback(): array
    {
        return $this->locked(function (): array {
            $applied = $this->applied();
            if ($applied === []) {
                return [];
            }
            $batch = max(array_column($applied, 'batch'));
            $files = $this->files();
            $pending = array_filter($applied, static fn(array $row): bool => $row['batch'] === $batch);
            krsort($pending, SORT_STRING);
            foreach ($pending as $name => $row) {
                if (!isset($files[$name])) {
                    throw new RuntimeException('An applied migration file is missing. Restore it before rollback.');
                }
                $this->load($name, $files[$name]);
            }
            $completed = [];
            foreach ($pending as $name => $row) {
                $this->loaded[$name]->down($this->connection);
                $this->connection->execute('DELETE FROM ' . $this->table . ' WHERE migration = :migration', ['migration' => $name]);
                $completed[] = $name;
            }
            return $completed;
        });
    }

    public function status(): array
    {
        return $this->locked(function (): array {
            $files = $this->files();
            $applied = $this->applied();
            $names = array_unique(array_merge(array_keys($files), array_keys($applied)));
            sort($names, SORT_STRING);
            return array_map(static fn(string $name): array => [
                'migration' => $name,
                'batch' => $applied[$name]['batch'] ?? null,
                'applied' => isset($applied[$name]),
                'available' => isset($files[$name]),
            ], $names);
        });
    }

    private function files(): array
    {
        $files = [];
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            $name = basename($file);
            if (is_link($file) || !is_file($file) || !is_readable($file)
                || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*\.php$/D', $name)) {
                throw new InvalidArgumentException('Migration files must be readable PHP files with simple, unique names.');
            }
            $files[$name] = $file;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function load(string $name, string $file): MigrationInterface
    {
        if (!isset($this->loaded[$name])) {
            $migration = require $file;
            if (!$migration instanceof MigrationInterface) {
                throw new UnexpectedValueException('Migration files must return a MigrationInterface instance.');
            }
            $this->loaded[$name] = $migration;
        }
        return $this->loaded[$name];
    }

    private function applied(): array
    {
        $applied = [];
        foreach ($this->connection->select('SELECT migration, batch FROM ' . $this->table . ' ORDER BY migration') as $row) {
            $row['batch'] = (int) $row['batch'];
            $applied[$row['migration']] = $row;
        }
        return $applied;
    }

    private function storage(): void
    {
        $nameType = $this->connection->driver() === 'mysql' ? 'VARBINARY(255)' : 'VARCHAR(255)';
        $this->connection->execute('CREATE TABLE IF NOT EXISTS ' . $this->table . ' (migration ' . $nameType . ' PRIMARY KEY, batch INTEGER NOT NULL, applied_at VARCHAR(32) NOT NULL)');
        if ($this->connection->driver() === 'sqlite') {
            $this->connection->execute('CREATE TABLE IF NOT EXISTS ' . $this->lockTable . ' (id INTEGER PRIMARY KEY, locked INTEGER NOT NULL)');
            $this->connection->execute('INSERT OR IGNORE INTO ' . $this->lockTable . ' (id, locked) VALUES (1, 0)');
        }
    }

    private function locked(callable $operation): array
    {
        if ($this->connection->pdo()->inTransaction()) {
            throw new LogicException('Run migrations outside an existing transaction.');
        }
        if ($this->connection->driver() === 'sqlite') {
            $this->storage();
            return $this->connection->transaction(function () use ($operation): array {
                $this->connection->execute('UPDATE ' . $this->lockTable . ' SET locked = locked WHERE id = 1');
                return $operation();
            });
        }
        $database = $this->connection->first('SELECT DATABASE() AS database_name');
        $name = 'iris_' . substr(hash('sha256', strtolower(($database['database_name'] ?? '') . ':' . $this->table)), 0, 56);
        $lock = $this->connection->first('SELECT GET_LOCK(:name, 10) AS acquired', ['name' => $name]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Another process is running database migrations.');
        }
        $failure = null;
        try {
            $this->storage();
            return $operation();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                $this->connection->first('SELECT RELEASE_LOCK(:name) AS released', ['name' => $name]);
            } catch (Throwable $releaseException) {
                if ($failure === null) {
                    throw $releaseException;
                }
            }
        }
    }
}
