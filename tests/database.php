<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Database\Connection;
use Core\Database\DatabaseManager;
use Core\Database\Migrator;

error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): void {
    if ((error_reporting() & $level) !== 0) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
});

function databaseAssert(bool $condition, string $message = 'Database assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function databaseRejects(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        databaseAssert($exception instanceof $type, 'Unexpected database exception type.');
        return;
    }
    throw new RuntimeException('Expected database rejection was not raised.');
}

function databaseFixture(): Connection
{
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $connection->execute('CREATE TABLE members (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, role TEXT, enabled INTEGER, quantity INTEGER, price TEXT)');
    $connection->table('members')->insert(['name' => 'Alice', 'role' => 'member', 'enabled' => true, 'quantity' => 2, 'price' => '19.95']);
    $connection->table('members')->insert(['name' => 'Bob', 'role' => null, 'enabled' => false, 'quantity' => 4, 'price' => '10.00']);
    $connection->table('members')->insert(['name' => 'Charlie', 'role' => 'member', 'enabled' => true, 'quantity' => 6, 'price' => '25.50']);
    return $connection;
}

function migrationDirectory(string $root): string
{
    $directory = $root . '/migrations-' . bin2hex(random_bytes(6));
    databaseAssert(mkdir($directory, 0700));
    return $directory;
}

function writeMigration(string $directory, string $name, string $up, string $down): void
{
    $source = '<?php return new class implements \\Core\\Database\\MigrationInterface {'
        . 'public function up(\\Core\\Database\\Connection $connection): void {' . $up . '}'
        . 'public function down(\\Core\\Database\\Connection $connection): void {' . $down . '}'
        . '};';
    databaseAssert(file_put_contents($directory . '/' . $name, $source) !== false);
}

function removeDatabaseFixtures(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            removeDatabaseFixtures($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/iris-database-tests-' . bin2hex(random_bytes(8));
databaseAssert(mkdir($directory, 0700));
$tests = [];
$tests['connections remain lazy until first PDO operation'] = static function () use ($directory): void {
    $path = $directory . '/lazy.sqlite';
    $connection = new Connection(['driver' => 'sqlite', 'database' => $path]);
    databaseAssert(!is_file($path) && $connection->quoteIdentifier('members.name') === '"members"."name"');
    $pdo = $connection->pdo();
    databaseAssert(is_file($path) && $pdo === $connection->pdo());
    $connection->disconnect();
    databaseAssert($pdo !== $connection->pdo());
};
$tests['PDO native scalar binding and first row semantics'] = static function (): void {
    $connection = databaseFixture();
    $row = $connection->first('SELECT :missing AS missing, :enabled AS enabled, :count AS count, :name AS name', ['missing' => null, 'enabled' => true, 'count' => 42, 'name' => 'Synthetic']);
    databaseAssert($row === ['missing' => null, 'enabled' => 1, 'count' => 42, 'name' => 'Synthetic']);
    databaseAssert($connection->first('SELECT name FROM members WHERE id = ?', [2]) === ['name' => 'Bob']);
    databaseAssert($connection->first('SELECT name FROM members WHERE id = ?', [999]) === null);
};
$tests['execute reports affected row counts'] = static function (): void {
    $connection = databaseFixture();
    databaseAssert($connection->execute('UPDATE members SET quantity = quantity + 1 WHERE id = ?', [1]) === 1);
    databaseAssert($connection->execute('DELETE FROM members WHERE id = ?', [999]) === 0);
};
$tests['SQLite foreign keys are enforced on managed connections'] = static function (): void {
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $connection->execute('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
    $connection->execute('CREATE TABLE children (parent_id INTEGER REFERENCES parents(id))');
    databaseRejects(static fn() => $connection->execute('INSERT INTO children (parent_id) VALUES (?)', [999]), PDOException::class);
    databaseAssert($connection->select('SELECT * FROM children') === []);
};
$tests['invalid binding values and aliases fail before execution'] = static function (): void {
    $connection = databaseFixture();
    databaseRejects(static fn() => $connection->first('SELECT :value', ['value' => []]), InvalidArgumentException::class);
    databaseRejects(static fn() => $connection->first('SELECT :value', ['value' => INF]), InvalidArgumentException::class);
    databaseRejects(static fn() => $connection->first('SELECT :value', ['value' => 'first', ':value' => 'second']), InvalidArgumentException::class);
    databaseRejects(static fn() => $connection->first('SELECT ?', [2 => 'value']), InvalidArgumentException::class);
};
$tests['transaction commits writes and returns callback value'] = static function (): void {
    $connection = databaseFixture();
    $value = $connection->transaction(static function (Connection $database): string {
        $database->table('members')->where('id', 1)->update(['quantity' => 9]);
        return 'committed';
    });
    databaseAssert($value === 'committed' && $connection->table('members')->where('id', 1)->first()['quantity'] === 9);
};
$tests['transaction rollback preserves the original Throwable'] = static function (): void {
    $connection = databaseFixture();
    $failure = new Error('Synthetic transaction error');
    try {
        $connection->transaction(static function (Connection $database) use ($failure): void {
            $database->table('members')->where('id', 1)->update(['quantity' => 99]);
            throw $failure;
        });
    } catch (Throwable $caught) {
        databaseAssert($caught === $failure);
    }
    databaseAssert($connection->table('members')->where('id', 1)->first()['quantity'] === 2 && !$connection->pdo()->inTransaction());
};
$tests['caught nested failure rolls back only its savepoint'] = static function (): void {
    $connection = databaseFixture();
    $connection->transaction(static function (Connection $database): void {
        $database->table('members')->where('id', 1)->update(['quantity' => 10]);
        try {
            $database->transaction(static function (Connection $nested): void {
                $nested->table('members')->where('id', 2)->update(['quantity' => 99]);
                throw new RuntimeException('Synthetic nested failure');
            });
        } catch (RuntimeException $exception) {
        }
        $database->table('members')->where('id', 3)->update(['quantity' => 30]);
    });
    databaseAssert(array_column($connection->table('members')->orderBy('id')->get(), 'quantity') === [10, 4, 30]);
};
$tests['outer rollback also undoes successful nested transactions'] = static function (): void {
    $connection = databaseFixture();
    databaseRejects(static fn() => $connection->transaction(static function (Connection $database): void {
        $database->transaction(static fn(Connection $nested) => $nested->table('members')->where('id', 1)->update(['quantity' => 99]));
        throw new RuntimeException('Synthetic outer failure');
    }), RuntimeException::class);
    databaseAssert($connection->table('members')->where('id', 1)->first()['quantity'] === 2);
};
$tests['external transaction boundaries and disconnect are guarded'] = static function (): void {
    $connection = databaseFixture();
    $connection->pdo()->beginTransaction();
    try {
        databaseRejects(static fn() => $connection->transaction(static fn() => null), LogicException::class);
        databaseRejects(static fn() => $connection->disconnect(), LogicException::class);
    } finally {
        $connection->pdo()->rollBack();
    }
    $connection->transaction(static function (Connection $database): void {
        databaseRejects(static fn() => $database->disconnect(), LogicException::class);
    });
};
$tests['unexpected manual commit does not masquerade as managed success'] = static function (): void {
    $connection = databaseFixture();
    databaseRejects(static fn() => $connection->transaction(static fn(Connection $database) => $database->pdo()->commit()), LogicException::class);
};
$tests['query builder selects filters sorts and paginates'] = static function (): void {
    $connection = databaseFixture();
    $rows = $connection->table('members')->select(['id', 'name'])->where('quantity', '>=', 2)->where('role', 'member')->orderBy('id', 'DESC')->limit(1, 1)->get();
    databaseAssert($rows === [['id' => 1, 'name' => 'Alice']]);
    databaseAssert($connection->table('members')->where('quantity', '>', 999)->first() === null);
};
$tests['query builder chains do not mutate their base query'] = static function (): void {
    $base = databaseFixture()->table('members');
    $filtered = $base->where('id', 1);
    databaseAssert($base->count() === 3 && $filtered->count() === 1);
    databaseAssert($base->limit(0)->get() === [] && $base->count() === 3);
};
$tests['query builder null and IN expressions preserve SQL semantics'] = static function (): void {
    $table = databaseFixture()->table('members');
    databaseAssert($table->where('role', null)->count() === 1);
    databaseAssert($table->where('role', '!=', null)->count() === 2);
    databaseAssert($table->whereIn('id', [1, 3])->count() === 2);
    databaseAssert($table->whereIn('id', [1], true)->count() === 2);
    databaseAssert($table->whereIn('id', [])->count() === 0);
};
$tests['query builder binds adversarial values rather than SQL fragments'] = static function (): void {
    $connection = databaseFixture();
    $payload = "Synthetic'); DROP TABLE members; --";
    $id = $connection->table('members')->insert(['name' => $payload]);
    databaseAssert(is_string($id) && $connection->table('members')->where('name', $payload)->first()['id'] === (int) $id);
    databaseAssert($connection->table('members')->count() === 4);
};
$tests['query builder qualified updates and deletes return affected counts'] = static function (): void {
    $table = databaseFixture()->table('members');
    databaseAssert($table->where('id', 1)->update(['name' => 'Changed']) === 1);
    databaseAssert($table->where('id', 1)->first()['name'] === 'Changed');
    databaseAssert($table->where('id', 1)->delete() === 1 && $table->count() === 2);
};
$tests['unqualified writes and ignored selection constraints are refused'] = static function (): void {
    $table = databaseFixture()->table('members');
    databaseRejects(static fn() => $table->update(['name' => 'Changed']), LogicException::class);
    databaseRejects(static fn() => $table->delete(), LogicException::class);
    databaseRejects(static fn() => $table->where('id', 1)->limit(1)->delete(), LogicException::class);
    databaseRejects(static fn() => $table->where('id', 1)->orderBy('id')->update(['name' => 'Changed']), LogicException::class);
    databaseRejects(static fn() => $table->where('id', 1)->insert(['name' => 'Changed']), LogicException::class);
    databaseRejects(static fn() => $table->whereIn('id', [], true)->delete(), LogicException::class);
    databaseAssert($table->count() === 3);
};
$tests['identifiers operators and ordering cannot inject SQL'] = static function (): void {
    $connection = databaseFixture();
    databaseRejects(static fn() => $connection->table('members; DROP TABLE members'), InvalidArgumentException::class);
    databaseRejects(static fn() => $connection->table('members')->where('id', '= 1 OR 1=1 --', 1), InvalidArgumentException::class);
    databaseRejects(static fn() => $connection->table('members')->orderBy('id', 'DESC; DROP TABLE members'), InvalidArgumentException::class);
    databaseRejects(static fn() => $connection->table('members')->select('COUNT(*)'), InvalidArgumentException::class);
    databaseRejects(static fn() => $connection->table('members')->insert(['name) VALUES (1); --' => 'value']), InvalidArgumentException::class);
    databaseAssert($connection->table('members')->count() === 3);
};
$tests['named database manager caches independent lazy connections'] = static function (): void {
    $manager = new DatabaseManager(['default' => 'primary', 'connections' => ['primary' => ['driver' => 'sqlite', 'database' => ':memory:'], 'reporting' => ['dsn' => 'sqlite::memory:']]]);
    $primary = $manager->connection();
    databaseAssert($primary === $manager->defaultConnection() && $primary === $manager->connection('primary'));
    $manager->setDefaultConnection('reporting');
    databaseAssert($manager->connection() !== $primary);
    databaseRejects(static fn() => $manager->connection('missing'), InvalidArgumentException::class);
    databaseRejects(static fn() => $manager->setDefaultConnection('missing'), InvalidArgumentException::class);
};
$tests['configuration validation does not initiate unrelated database connections'] = static function (): void {
    databaseRejects(static fn() => new DatabaseManager(['connections' => 'invalid']), InvalidArgumentException::class);
    databaseRejects(static fn() => new DatabaseManager(['connections' => []]), InvalidArgumentException::class);
    databaseRejects(static fn() => new DatabaseManager(['connections' => null]), InvalidArgumentException::class);
    databaseRejects(static fn() => new DatabaseManager(['default' => null, 'connections' => ['main' => []]]), InvalidArgumentException::class);
    databaseRejects(static fn() => (new Connection(['dsn' => []]))->driver(), InvalidArgumentException::class);
    databaseRejects(static fn() => (new Connection(['driver' => 'unsupported']))->pdo(), InvalidArgumentException::class);
    databaseRejects(static fn() => (new Connection(['driver' => 'sqlite']))->pdo(), InvalidArgumentException::class);
    databaseAssert((new Connection(['driver' => 'mysql', 'database' => 'synthetic']))->quoteIdentifier('members.id') === '`members`.`id`');
};
$tests['legacy models reuse their explicitly injected connection'] = static function (): void {
    $connection = databaseFixture();
    $model = new class extends \Core\Model {
        public static function database(): PDO
        {
            return parent::getDB();
        }
    };
    $model::setConnection($connection);
    databaseAssert($model::database() === $connection->pdo() && $model::database() === $model::database());
};
$tests['migrations apply deterministically once and roll back latest batch in reverse'] = static function () use ($directory): void {
    $path = migrationDirectory($directory);
    writeMigration($path, '002_second.php', '$connection->execute("CREATE TABLE second_data (id INTEGER)");', '$connection->execute("DROP TABLE second_data");');
    writeMigration($path, '001_first.php', '$connection->execute("CREATE TABLE first_data (id INTEGER)");', '$connection->execute("DROP TABLE first_data");');
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($connection, $path);
    databaseAssert(array_column($migrator->status(), 'applied') === [false, false]);
    databaseAssert($migrator->migrate() === ['001_first.php', '002_second.php']);
    databaseAssert($migrator->migrate() === [] && array_column($migrator->status(), 'batch') === [1, 1]);
    databaseAssert($migrator->rollback() === ['002_second.php', '001_first.php']);
    databaseAssert($migrator->rollback() === [] && array_column($migrator->status(), 'applied') === [false, false]);
};
$tests['migration rollback targets only the latest batch'] = static function () use ($directory): void {
    $path = migrationDirectory($directory);
    writeMigration($path, '001_first.php', '$connection->execute("CREATE TABLE first_data (id INTEGER)");', '$connection->execute("DROP TABLE first_data");');
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($connection, $path);
    $migrator->migrate();
    writeMigration($path, '002_second.php', '$connection->execute("CREATE TABLE second_data (id INTEGER)");', '$connection->execute("DROP TABLE second_data");');
    databaseAssert($migrator->migrate() === ['002_second.php']);
    databaseAssert(array_column($migrator->status(), 'batch') === [1, 2]);
    databaseAssert($migrator->rollback() === ['002_second.php']);
    databaseAssert($connection->first('SELECT name FROM sqlite_master WHERE name = ?', ['first_data']) !== null);
};
$tests['failed SQLite migration batch rolls back schema and metadata'] = static function () use ($directory): void {
    $path = migrationDirectory($directory);
    writeMigration($path, '001_first.php', '$connection->execute("CREATE TABLE first_data (id INTEGER)");', '$connection->execute("DROP TABLE first_data");');
    writeMigration($path, '002_failure.php', 'throw new \\RuntimeException("Synthetic migration failure");', '');
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($connection, $path);
    databaseRejects(static fn() => $migrator->migrate(), RuntimeException::class);
    databaseAssert($connection->first('SELECT name FROM sqlite_master WHERE name = ?', ['first_data']) === null);
    databaseAssert(array_column($migrator->status(), 'applied') === [false, false]);
};
$tests['failed SQLite rollback restores dropped schema and metadata'] = static function () use ($directory): void {
    $path = migrationDirectory($directory);
    writeMigration($path, '001_first.php', '$connection->execute("CREATE TABLE first_data (id INTEGER)");', '$connection->execute("DROP TABLE first_data");');
    writeMigration($path, '002_second.php', '$connection->execute("CREATE TABLE second_data (id INTEGER)");', '$connection->execute("DROP TABLE second_data"); throw new \\RuntimeException("Synthetic rollback failure");');
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($connection, $path);
    $migrator->migrate();
    databaseRejects(static fn() => $migrator->rollback(), RuntimeException::class);
    databaseAssert($connection->first('SELECT name FROM sqlite_master WHERE name = ?', ['second_data']) !== null);
    databaseAssert(array_column($migrator->status(), 'applied') === [true, true]);
};
$tests['missing applied migration files block changes and remain visible in status'] = static function () use ($directory): void {
    $path = migrationDirectory($directory);
    writeMigration($path, '001_first.php', '$connection->execute("CREATE TABLE first_data (id INTEGER)");', '$connection->execute("DROP TABLE first_data");');
    $migrator = new Migrator(new Connection(['driver' => 'sqlite', 'database' => ':memory:']), $path);
    $migrator->migrate();
    unlink($path . '/001_first.php');
    databaseAssert($migrator->status()[0] === ['migration' => '001_first.php', 'batch' => 1, 'applied' => true, 'available' => false]);
    databaseRejects(static fn() => $migrator->migrate(), RuntimeException::class);
    databaseRejects(static fn() => $migrator->rollback(), RuntimeException::class);
};
$tests['invalid migration classes and metadata identifiers are rejected'] = static function () use ($directory): void {
    $path = migrationDirectory($directory);
    file_put_contents($path . '/001_invalid.php', '<?php return 1;');
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    databaseRejects(static fn() => (new Migrator($connection, $path))->migrate(), UnexpectedValueException::class);
    databaseRejects(static fn() => new Migrator($connection, $path, 'migration; DROP TABLE members'), InvalidArgumentException::class);
};
$tests['migration operations cannot inherit an unmanaged outer transaction'] = static function () use ($directory): void {
    $path = migrationDirectory($directory);
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($connection, $path);
    $connection->transaction(static function () use ($migrator): void {
        databaseRejects(static fn() => $migrator->migrate(), LogicException::class);
    });
};
$tests['SQLite migration contention refuses a competing writer and succeeds after release'] = static function () use ($directory): void {
    $path = migrationDirectory($directory);
    writeMigration($path, '001_first.php', '$connection->execute("CREATE TABLE first_data (id INTEGER)");', '$connection->execute("DROP TABLE first_data");');
    $database = $directory . '/migration-lock.sqlite';
    $first = new Connection(['driver' => 'sqlite', 'database' => $database, 'busy_timeout' => 20]);
    $second = new Connection(['driver' => 'sqlite', 'database' => $database, 'busy_timeout' => 20]);
    (new Migrator($first, $path))->status();
    $first->pdo()->beginTransaction();
    $first->execute('UPDATE iris_migrations_lock SET locked = locked WHERE id = 1');
    try {
        databaseRejects(static fn() => (new Migrator($second, $path))->migrate(), PDOException::class);
    } finally {
        $first->pdo()->rollBack();
    }
    databaseAssert((new Migrator($second, $path))->migrate() === ['001_first.php']);
};

$passed = 0;
$failures = [];
try {
    foreach ($tests as $name => $operation) {
        try {
            $operation();
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . get_class($exception) . ' (' . $exception->getMessage() . ')';
        }
    }
} finally {
    removeDatabaseFixtures($directory);
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
}
fwrite(STDOUT, $passed . ' database tests passed; ' . count($failures) . ' failed.' . PHP_EOL);
exit($failures === [] ? 0 : 1);
