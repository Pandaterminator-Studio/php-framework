<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Database\Connection;
use Core\Http\Response;

function paginationEquals(mixed $expected, mixed $actual): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Unexpected pagination result: ' . var_export($actual, true));
    }
}

function paginationRejects(string $exceptionClass, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }
        throw $exception;
    }
    throw new RuntimeException('Expected ' . $exceptionClass);
}

$connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection->execute('CREATE TABLE entries (id INTEGER PRIMARY KEY, title TEXT, enabled INTEGER)');
$entries = $connection->table('entries');
foreach (['Alpha', 'Hidden', 'Beta', 'Alpha', 'Gamma', 'Beta'] as $index => $title) {
    $entries->insert(['id' => $index + 1, 'title' => $title, 'enabled' => $title !== 'Hidden']);
}
$ordered = $entries->select(['id', 'title'])->where('enabled', true)->orderBy('title')->orderBy('id', 'DESC');
$tests = [];

$tests['filters, selected columns and ordering carry into page reads'] = static function () use ($ordered): void {
    paginationEquals([
        'data' => [['id' => 6, 'title' => 'Beta'], ['id' => 3, 'title' => 'Beta']],
        'meta' => ['current_page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3],
    ], $ordered->paginate('2', '2'));
};
$tests['last page and past-end requests keep distinct metadata'] = static function () use ($ordered): void {
    paginationEquals([['id' => 5, 'title' => 'Gamma']], $ordered->paginate(3, 2)['data']);
    paginationEquals([
        'data' => [],
        'meta' => ['current_page' => PHP_INT_MAX, 'per_page' => 100, 'total' => 5, 'last_page' => 1],
    ], $ordered->paginate((string) PHP_INT_MAX, 100));
};
$tests['empty queries have one empty page and serialize as a JSON list'] = static function () use ($ordered): void {
    $page = $ordered->where('id', 0)->paginate();
    paginationEquals('{"data":[],"meta":{"current_page":1,"per_page":25,"total":0,"last_page":1}}', Response::json($page)->body());
};
$tests['exact page boundaries avoid a phantom last page'] = static function () use ($ordered): void {
    $even = $ordered->where('id', '!=', 5);
    paginationEquals(2, $even->paginate(2, 2)['meta']['last_page']);
    paginationEquals([], $even->paginate(3, 2)['data']);
};
$tests['page reads leave the original builder reusable'] = static function () use ($ordered): void {
    $ordered->paginate(3, 2);
    paginationEquals([4, 1, 6, 3, 5], array_column($ordered->get(), 'id'));
    paginationEquals([4, 1], array_column($ordered->paginate(1, 2)['data'], 'id'));
};
$tests['missing order and manual limits are rejected'] = static function () use ($entries, $ordered): void {
    paginationRejects(LogicException::class, static fn() => $entries->paginate());
    paginationRejects(LogicException::class, static fn() => $ordered->limit(0)->paginate());
    paginationRejects(LogicException::class, static fn() => $ordered->limit(5, 2)->paginate());
};
$tests['invalid parameter types, formats, ranges and sizes fail before querying'] = static function () use ($connection): void {
    $unavailable = $connection->table('missing_table')->orderBy('id');
    foreach ([0, -1, true, false, null, 1.0, 2.5, [], new stdClass(), '', '+1', '01', '1.0', '1e1', ' 1', '1 ', (string) PHP_INT_MAX . '0'] as $invalid) {
        paginationRejects(InvalidArgumentException::class, static fn() => $unavailable->paginate($invalid));
        paginationRejects(InvalidArgumentException::class, static fn() => $unavailable->paginate(1, $invalid));
    }
    paginationRejects(InvalidArgumentException::class, static fn() => $unavailable->paginate(1, '101'));
};

foreach ($tests as $name => $test) {
    try {
        $test();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
echo count($tests) . ' pagination tests passed.' . PHP_EOL;
