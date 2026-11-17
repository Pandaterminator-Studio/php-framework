# Database connections and migrations

The database layer is independent of application controllers and the example website. It supports PDO MySQL and SQLite connections, bound queries, nested transactions, a small query builder, and file-based migrations. Install the matching PHP PDO driver. PostgreSQL and other PDO drivers are not implemented by this layer.

## Configure connections

```php
use Core\Database\DatabaseManager;

$database = new DatabaseManager([
    'default' => 'main',
    'connections' => [
        'main' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => getenv('IRIS_DB_DATABASE'),
            'username' => getenv('IRIS_DB_USER'),
            'password' => getenv('IRIS_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ],
        'testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ],
    ],
]);

$connection = $database->defaultConnection();
$testing = $database->connection('testing');
```

Connections are cached by name and opened lazily. Constructing the manager does not contact a database. `setDefaultConnection('testing')` changes future default lookups without replacing previously returned connections. A flat connection configuration is also accepted by `DatabaseManager`; its connection name is `default`.

`Connection` also accepts an explicit trusted DSN such as `sqlite:/absolute/path/database.sqlite`. MySQL DSNs are supported too. The legacy configuration keys `name` and `user` are aliases for `database` and `username`. Use an absolute SQLite path outside the public document root; the parent directory must already exist and be writable. SQLite foreign-key enforcement is enabled. `busy_timeout` configures its writer wait in milliseconds, defaulting to 5000.

PDO exception mode, associative fetches, native prepares, and non-persistent connections are enforced. Other PDO attributes may be supplied through `options`. Credentials belong in the environment or injected configuration, never source control or responses.

## Bound queries

```php
$members = $connection->select(
    'SELECT id, name FROM members WHERE enabled = :enabled',
    ['enabled' => true],
);
$member = $connection->first('SELECT id, name FROM members WHERE id = ?', [42]);
$changed = $connection->execute('UPDATE members SET name = :name WHERE id = :id', [
    'name' => 'Updated name',
    'id' => 42,
]);
```

`select()` returns an array of associative rows. `first()` returns an associative row or `null`. `execute()` returns the PDO affected-row count; driver behavior differs, including MySQL's distinction between changed and matched rows. `lastInsertId()` returns the driver's string value.

Bindings accept null, booleans, integers, strings, and finite floats. Integers and booleans use their PDO parameter types. Represent money with integer cents or decimal strings. Pass a list for positional bindings or an associative array for named bindings; use each named placeholder once when native MySQL prepares are enabled. Bind values, never SQL identifiers. `quoteIdentifier()` validates simple or dotted identifiers before applying driver-specific quoting; it does not accept arbitrary expressions.

## Query builder

```php
$members = $connection->table('members')
    ->select(['id', 'name'])
    ->where('enabled', true)
    ->where('quantity', '>=', 2)
    ->orderBy('id', 'DESC')
    ->limit(20, 0)
    ->get();

$member = $connection->table('members')->where('id', 42)->first();
$count = $connection->table('members')->whereIn('id', [1, 2, 3])->count();
$id = $connection->table('members')->insert(['name' => 'New member']);
$changed = $connection->table('members')->where('id', 42)->update(['name' => 'Changed']);
$deleted = $connection->table('members')->where('id', 42)->delete();
```

Chains return cloned builders, so reusing a base query does not inherit another chain's filters. Conditions are combined with `AND`. Supported comparisons are `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, and `NOT LIKE`. Comparing null with equality or inequality produces `IS NULL` or `IS NOT NULL`. `whereNull($column, true)` and `whereIn($column, $values, true)` select the negated form. An empty `IN` list matches no rows; an empty negated list imposes no restriction. `count()` counts all matching rows, independently of ordering and pagination.

`update()` and `delete()` require an explicit condition; an empty negated `IN` list alone does not qualify. They reject ordering and limits, which are not portable across the supported drivers. This protects against accidentally omitted conditions; it does not prove a condition is selective or that the user is authorized. `insert()` rejects selection constraints instead of silently ignoring them. Use authorized editable-field allowlists before passing application data to write methods. The builder does not provide joins, raw expressions, schema generation, relationships, or an ORM.

## Paginate a query

Call `paginate(page: 1, perPage: 25)` on an ordered query. Include a unique tie-breaker such as the primary key when several rows can share the same sort value.

```php
$paging = \Core\Validation\Validator::validate($request->query(), [
    'page' => 'integer|min:1',
    'per_page' => 'integer|between:1,100',
]);

$page = $connection->table('members')
    ->select(['id', 'name'])
    ->where('enabled', true)
    ->orderBy('name')
    ->orderBy('id')
    ->paginate($paging['page'] ?? 1, $paging['per_page'] ?? 25);

return \Core\Http\Response::json($page);
```

`data` holds the selected rows. `meta` contains the integer fields `current_page`, `per_page`, `total` and `last_page`. The total counts all rows matching the filters. An empty result has one empty page. Requests beyond the last page return empty data and retain their requested page number. The original query can be reused.

Both arguments accept positive PHP integers or their canonical decimal strings. The maximum page size is 100. Invalid arguments raise `InvalidArgumentException`. Validating query parameters as shown gives HTTP clients a field-specific 422 response. A missing `orderBy()` or a previous `limit()` raises `LogicException`.

Pagination counts matching rows first and then reads the requested slice if it exists. Concurrent changes can shift rows across pages or change the count between reads. Use a transaction with snapshot isolation when both reads within one call must see the same state.

## Transactions

```php
$result = $connection->transaction(function (Core\Database\Connection $database): int {
    $database->table('members')->where('id', 42)->update(['quantity' => 3]);
    return 42;
});
```

A successful callback commits and returns its result. A thrown `Throwable` rolls back and is rethrown as the original object. Nested calls use savepoints: a caught inner failure can roll back its own changes while the outer transaction continues. An outer failure also rolls back successful inner work. Callbacks receive the same connection.

Manage transaction boundaries through `Connection::transaction()`. Do not manually commit, roll back, change connections, or execute statements that implicitly commit inside a managed transaction. `pdo()` remains available for legacy code, but a transaction started directly on PDO cannot be adopted by the transaction wrapper. Disconnecting an active transaction is refused.

Transactions only cover database work on the participating connection and transactional tables. Files, emails, external APIs, and non-transactional database engines require separate recovery plans.

## Migrations

Create a migration directory outside `public/`. Each `.php` file must return a `MigrationInterface` instance. Timestamp-prefixed filenames provide a natural stable ordering. Treat applied files as immutable and add a new file for later changes.

```php
<?php

use Core\Database\Connection;
use Core\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(Connection $connection): void
    {
        $connection->execute('CREATE TABLE example_items (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE example_items');
    }
};
```

```php
$migrator = new Core\Database\Migrator($connection, $migrationDirectory);
$applied = $migrator->migrate();
$status = $migrator->status();
$rolledBack = $migrator->rollback();
```

`migrate()` applies pending files in bytewise filename order and returns their filenames, including `.php`. Re-running it skips recorded files. `rollback()` rolls back the latest batch in reverse filename order and returns those filenames. `status()` returns rows with `migration`, `batch`, `applied`, and `available`; a missing applied file remains visible and blocks further migration or rollback until restored. `status()` initializes migration metadata if needed. Migration PHP is trusted executable source code, not user uploads. Use anonymous migration classes as shown so files can be loaded in independent migrator instances without redeclaring named classes.

Metadata defaults to `iris_migrations`. A custom simple table name may be passed as the third constructor argument. MySQL stores migration filenames as binary strings so case-sensitive filenames cannot collide through the database's text collation. The advisory-lock scope normalizes database and metadata-table names to coordinate case aliases. SQLite also creates a corresponding `_lock` table. Migration operations must run outside an existing transaction.

SQLite applies or rolls back each batch inside one transaction after obtaining a database writer lock. A competing writer waits according to `busy_timeout` or receives a database-busy error. MySQL uses a session advisory lock scoped to the selected database and metadata table. Concurrent migration tools must use this migrator and the same metadata table for that coordination to apply.

MySQL migrations are deliberately not wrapped in a batch transaction: many schema statements cause implicit commits, so transactional DDL rollback cannot be promised. Each successful migration is recorded after `up()` returns; a later failure leaves earlier successful migrations recorded. A failing migration may have partially changed MySQL schema and requires inspection and a forward repair before retrying. The equivalent limitation applies to a failing MySQL `down()`. Back up data and test recovery before production migration. Use explicit transactions inside an individual migration only for compatible DML, without mixing in statements that implicitly commit. See [MySQL implicit commits](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html), [MySQL advisory locks](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html), and [SQLite transactions](https://www.sqlite.org/lang_transaction.html).

The regression suite exercises real isolated SQLite databases, transactions, migration failures, and writer contention. It does not verify a live MySQL server or any customer database.

## Legacy models

```php
Core\Model::setConnection($database->defaultConnection());
```

Existing subclasses can continue using protected `getDB(): PDO`; repeated calls reuse the injected connection. Injection is recommended for reusable applications and tests. Without injection, the legacy adapter uses `App\Config::database()` if that application class exists. Outside the example application it can use `IRIS_DB_DRIVER`, `IRIS_DB_DATABASE` or `IRIS_DB_DSN`, `IRIS_DB_HOST`, `IRIS_DB_PORT`, `IRIS_DB_USER`, and `IRIS_DB_PASSWORD`. No database connection is opened until a model actually requests it.
