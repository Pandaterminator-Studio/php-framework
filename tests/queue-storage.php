<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Database\Connection;
use Core\Queue\DatabaseQueue;
use Core\Queue\ReservedJob;

function storageCheck(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Queue storage expectation failed.');
    }
}

function storageRejects(string $type, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if (!$exception instanceof $type) {
            throw $exception;
        }
        return;
    }
    throw new RuntimeException("Expected $type.");
}

function storageFixture(int &$now): array
{
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $queue = new DatabaseQueue($connection, static function () use (&$now): int { return $now; });
    $queue->install();
    return [$queue, $connection];
}

$tests = [];
$tests['explicit and repeatable installation leaves existing jobs intact'] = static function (): void {
    $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $queue = new DatabaseQueue($connection);
    storageRejects(PDOException::class, static fn() => $queue->push('first'));
    storageCheck($connection->first("SELECT name FROM sqlite_master WHERE name='iris_jobs'") === null);
    $queue->install();
    $id = $queue->push('first');
    $queue->install();
    storageCheck($queue->reserve()?->id === $id);
};
$tests['JSON types survive delayed dispatch and completion removes the row'] = static function (): void {
    $now = 1000;
    [$queue, $connection] = storageFixture($now);
    $payload = ['record' => ['id' => 42, 'text' => 'é', 'numbers' => [1.0, 0, false, null]]];
    $id = $queue->push('demo.save', $payload, 3);
    storageCheck(preg_match('/^[0-9a-f]{32}$/D', $id) === 1 && $queue->reserve() === null);
    $now += 3;
    $job = $queue->reserve();
    storageCheck($job->payload === $payload && $job->id === $id && $job->attempts === 1 && $job->maxAttempts === 3);
    storageCheck($queue->complete($job) && !$queue->complete($job) && $connection->table('iris_jobs')->count() === 0);
};
$tests['enqueue participates in an application transaction'] = static function (): void {
    $now = 1000;
    [$queue, $connection] = storageFixture($now);
    storageRejects(RuntimeException::class, static function () use ($queue, $connection): void {
        $connection->transaction(static function () use ($queue): void {
            $queue->push('rolled.back');
            throw new RuntimeException('fixture rollback');
        });
    });
    storageCheck($queue->reserve() === null);
    $id = $connection->transaction(static fn() => $queue->push('committed'));
    storageCheck($queue->reserve()?->id === $id);
};
$tests['installation and consumption refuse an open application transaction'] = static function (): void {
    $now = 1000;
    [$queue, $connection] = storageFixture($now);
    $id = $queue->push('waiting');
    $connection->transaction(static function () use ($queue): void {
        storageRejects(LogicException::class, static fn() => $queue->install());
        storageRejects(LogicException::class, static fn() => $queue->reserve());
    });
    storageCheck($queue->reserve()?->id === $id);
};
$tests['expired claims are fenced before and after redelivery'] = static function (): void {
    $now = 1000;
    [$queue] = storageFixture($now);
    $id = $queue->push('work');
    $original = $queue->reserve(2);
    $now++;
    storageCheck($queue->reserve() === null && !$queue->retry($id));
    $now++;
    storageCheck(!$queue->complete($original) && !$queue->release($original));
    $replacement = $queue->reserve(2);
    storageCheck($replacement->id === $id && $replacement->token !== $original->token && $replacement->attempts === 2);
    storageCheck(!$queue->release($original) && !$queue->complete($original) && $queue->complete($replacement));
};
$tests['a release delays redelivery and failures keep only safe listing metadata'] = static function (): void {
    $now = 1000;
    [$queue] = storageFixture($now);
    $id = $queue->push('export', ['private' => 'fixture'], maxAttempts: 2);
    $first = $queue->reserve();
    storageCheck($queue->release($first, 5) && $queue->reserve() === null && $queue->failed() === []);
    $now += 5;
    $last = $queue->reserve();
    storageCheck($queue->release($last, 30) && !$queue->complete($last) && $queue->reserve() === null);
    storageCheck($queue->failed() === [['id' => $id, 'name' => 'export', 'attempts' => 2]]);
    storageCheck($queue->retry($id) && !$queue->retry($id));
    $retry = $queue->reserve();
    storageCheck($retry->id === $id && $retry->attempts === 1 && $retry->payload === ['private' => 'fixture']);
};
$tests['a worker lost on its final attempt becomes retryable without another reservation'] = static function (): void {
    $now = 1000;
    [$queue] = storageFixture($now);
    $id = $queue->push('last', maxAttempts: 1);
    $orphan = $queue->reserve(1);
    storageCheck($queue->failed() === [] && !$queue->retry($id));
    $now++;
    storageCheck($queue->failed(1) === [['id' => $id, 'name' => 'last', 'attempts' => 1]]);
    storageCheck($queue->retry($id) && !$queue->complete($orphan));
    storageCheck($queue->reserve()?->attempts === 1);
};
$tests['settlement uses stored attempt limits and requires the actual lease token'] = static function (): void {
    $now = 1000;
    [$queue] = storageFixture($now);
    $id = $queue->push('one', maxAttempts: 1);
    $job = $queue->reserve();
    $invalidToken = new ReservedJob($id, 'other', [], 0, 99, bin2hex(random_bytes(32)));
    storageCheck(!$queue->release($invalidToken) && !$queue->complete($invalidToken));
    $alteredMetadata = new ReservedJob($id, 'other', [], 0, 99, $job->token);
    storageCheck($queue->release($alteredMetadata) && $queue->failed()[0]['attempts'] === 1);
};
$tests['corrupt stored data is quarantined while a later job can still run'] = static function (): void {
    $now = 1000;
    [$queue, $connection] = storageFixture($now);
    $bad = $queue->push('bad');
    $connection->execute('UPDATE iris_jobs SET payload = ? WHERE id = ?', ['[1e400]', $bad]);
    $now++;
    $ok = $queue->push('good');
    storageCheck($queue->reserve()?->id === $ok && $queue->failed() === [['id' => $bad, 'name' => 'bad', 'attempts' => 0]]);
};
$tests['payloads enforce byte and depth bounds without invoking object methods'] = static function (): void {
    $now = 1000;
    [$queue] = storageFixture($now);
    $object = new class implements JsonSerializable {
        public function jsonSerialize(): mixed { throw new LogicException('Object method was called.'); }
    };
    $recursive = [];
    $recursive['self'] = &$recursive;
    $resource = fopen('php://temp', 'rb');
    try {
        foreach ([['nested' => [$object]], [$resource], [$recursive], [INF], ["\xff"], [str_repeat('x', 65537)]] as $invalid) {
            storageRejects(InvalidArgumentException::class, static fn() => $queue->push('invalid', $invalid));
        }
    } finally {
        fclose($resource);
    }
    storageCheck($queue->reserve() === null);
    $payload = [str_repeat('x', 65532)];
    $queue->push('boundary', $payload);
    storageCheck($queue->reserve()?->payload === $payload);
};
$tests['invalid operational values are rejected before mutation'] = static function (): void {
    $now = 1000;
    [$queue] = storageFixture($now);
    foreach (['', 'with space', '9number', str_repeat('x', 129)] as $name) {
        storageRejects(InvalidArgumentException::class, static fn() => $queue->push($name));
    }
    foreach ([-1, DatabaseQueue::MAX_DELAY + 1] as $delay) {
        storageRejects(InvalidArgumentException::class, static fn() => $queue->push('job', [], $delay));
    }
    foreach ([0, 101] as $value) {
        storageRejects(InvalidArgumentException::class, static fn() => $queue->push('job', [], 0, $value));
        storageRejects(InvalidArgumentException::class, static fn() => $queue->failed($value));
    }
    foreach ([0, DatabaseQueue::MAX_LEASE + 1] as $lease) {
        storageRejects(InvalidArgumentException::class, static fn() => $queue->reserve($lease));
    }
    storageRejects(InvalidArgumentException::class, static fn() => $queue->retry(''));
    storageCheck(!$queue->retry(str_repeat('0', 32)) && $queue->reserve() === null);
};

foreach ($tests as $name => $test) {
    try {
        $test();
    } catch (Throwable $exception) {
        fwrite(STDERR, $name . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
echo count($tests) . " queue storage tests passed.\n";
