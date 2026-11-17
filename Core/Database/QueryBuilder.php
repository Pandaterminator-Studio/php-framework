<?php

namespace Core\Database;

use InvalidArgumentException;
use LogicException;

final class QueryBuilder
{
    private array $columns = ['*'];
    private array $conditions = [];
    private array $bindings = [];
    private array $orders = [];
    private ?int $limit = null;
    private int $offset = 0;
    private string $table;

    public function __construct(private Connection $connection, string $table)
    {
        $this->table = $connection->quoteIdentifier($table);
    }

    public function select(array|string $columns = ['*']): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        if ($columns === [] || !array_is_list($columns)) {
            throw new InvalidArgumentException('Select columns must be a non-empty list.');
        }
        $query = clone $this;
        $query->columns = array_map(function ($column): string {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Select columns must be SQL identifiers.');
            }
            if ($column === '*') {
                return '*';
            }
            if (str_ends_with($column, '.*')) {
                return $this->connection->quoteIdentifier(substr($column, 0, -2)) . '.*';
            }
            return $this->connection->quoteIdentifier($column);
        }, $columns);
        return $query;
    }

    public function where(string $column, $operator, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        if (!is_string($operator) || !in_array(strtoupper(trim($operator)), ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'], true)) {
            throw new InvalidArgumentException('Unsupported SQL comparison operator.');
        }
        $operator = strtoupper(trim($operator));
        if ($value === null) {
            if (!in_array($operator, ['=', '!=', '<>'], true)) {
                throw new InvalidArgumentException('Null comparisons require equality or inequality.');
            }
            return $this->whereNull($column, $operator !== '=');
        }
        $query = clone $this;
        $parameter = 'where_' . count($query->bindings);
        $query->conditions[] = $this->connection->quoteIdentifier($column) . ' ' . $operator . ' :' . $parameter;
        $query->bindings[$parameter] = $value;
        return $query;
    }

    public function whereNull(string $column, bool $not = false): self
    {
        $query = clone $this;
        $query->conditions[] = $this->connection->quoteIdentifier($column) . ($not ? ' IS NOT NULL' : ' IS NULL');
        return $query;
    }

    public function whereIn(string $column, array $values, bool $not = false): self
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException('IN values must be a list.');
        }
        $query = clone $this;
        if ($values === []) {
            $query->conditions[] = $not ? '1 = 1' : '1 = 0';
            return $query;
        }
        $parameters = [];
        foreach ($values as $value) {
            $parameter = 'where_' . count($query->bindings);
            $query->bindings[$parameter] = $value;
            $parameters[] = ':' . $parameter;
        }
        $query->conditions[] = $this->connection->quoteIdentifier($column) . ($not ? ' NOT IN (' : ' IN (') . implode(', ', $parameters) . ')';
        return $query;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Sort direction must be ASC or DESC.');
        }
        $query = clone $this;
        $query->orders[] = $this->connection->quoteIdentifier($column) . ' ' . $direction;
        return $query;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 0 || $offset < 0) {
            throw new InvalidArgumentException('Limit and offset cannot be negative.');
        }
        $query = clone $this;
        $query->limit = $limit;
        $query->offset = $offset;
        return $query;
    }

    public function get(): array
    {
        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->table . $this->whereSql();
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset;
        }
        return $this->connection->select($sql, $this->bindings);
    }

    public function first(): ?array
    {
        return $this->limit(1, $this->offset)->get()[0] ?? null;
    }

    public function count(): int
    {
        $row = $this->connection->first('SELECT COUNT(*) AS iris_count FROM ' . $this->table . $this->whereSql(), $this->bindings);
        return (int) $row['iris_count'];
    }

    public function paginate(mixed $page = 1, mixed $perPage = 25): array
    {
        $page = self::paginationInteger($page, PHP_INT_MAX, 'page');
        $perPage = self::paginationInteger($perPage, 100, 'perPage');
        if ($this->orders === []) {
            throw new LogicException('Pagination requires an explicit orderBy().');
        }
        if ($this->limit !== null) {
            throw new LogicException('Pagination cannot be combined with a manual limit or offset.');
        }

        $total = $this->count();
        $lastPage = $total === 0 ? 1 : intdiv($total - 1, $perPage) + 1;
        $rows = [];
        if ($total > 0 && $page <= $lastPage) {
            $rows = $this->limit($perPage, ($page - 1) * $perPage)->get();
        }

        return [
            'data' => $rows,
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ];
    }

    public function insert(array $values): string
    {
        if ($this->conditions !== [] || $this->orders !== [] || $this->limit !== null) {
            throw new LogicException('Insert queries cannot have selection constraints.');
        }
        [$columns, $parameters, $bindings] = $this->writeValues($values, 'insert_');
        $this->connection->execute('INSERT INTO ' . $this->table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $parameters) . ')', $bindings);
        return $this->connection->lastInsertId();
    }

    public function update(array $values): int
    {
        $this->assertWriteScope();
        [$columns, $parameters, $bindings] = $this->writeValues($values, 'update_');
        $assignments = [];
        foreach ($columns as $index => $column) {
            $assignments[] = $column . ' = ' . $parameters[$index];
        }
        return $this->connection->execute('UPDATE ' . $this->table . ' SET ' . implode(', ', $assignments) . $this->whereSql(), array_merge($bindings, $this->bindings));
    }

    public function delete(): int
    {
        $this->assertWriteScope();
        return $this->connection->execute('DELETE FROM ' . $this->table . $this->whereSql(), $this->bindings);
    }

    private function whereSql(): string
    {
        return $this->conditions === [] ? '' : ' WHERE (' . implode(') AND (', $this->conditions) . ')';
    }

    private static function paginationInteger(mixed $value, int $maximum, string $parameter): int
    {
        if (is_string($value) && (string) (int) $value === $value) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > $maximum) {
            throw new InvalidArgumentException("Pagination parameter $parameter must be an integer between 1 and $maximum.");
        }
        return $value;
    }

    private function assertWriteScope(): void
    {
        if ($this->conditions === [] || array_diff($this->conditions, ['1 = 1']) === []) {
            throw new LogicException('Update and delete require an explicit condition.');
        }
        if ($this->orders !== [] || $this->limit !== null) {
            throw new LogicException('Write queries do not support ordering or limits.');
        }
    }

    private function writeValues(array $values, string $prefix): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Write values cannot be empty.');
        }
        $columns = [];
        $parameters = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            if (!is_string($column) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column)) {
                throw new InvalidArgumentException('Write values must have SQL column names.');
            }
            $parameter = $prefix . count($bindings);
            $columns[] = $this->connection->quoteIdentifier($column);
            $parameters[] = ':' . $parameter;
            $bindings[$parameter] = $value;
        }
        return [$columns, $parameters, $bindings];
    }
}
