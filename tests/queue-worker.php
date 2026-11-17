<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Application;
use Core\Config;
use Core\Container;
use Core\Database\Connection;
use Core\Queue\DatabaseQueue;
use Core\Queue\JobHandler;
use Core\Queue\Worker;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class QueueWorkerRecorder implements JobHandler
{
    public array $received = [];
    public ?Closure $action = null;

    public function handle(array $payload, string $jobId): void
    {
        $this->received[] = [$jobId, $payload];
        if ($this->action !== null) {
            ($this->action)();
        }
    }
}

function workerCheck(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Worker assertion failed.');
    }
}

function workerFixture(int &$now): array
{
    $queue = new DatabaseQueue(new Connection(['driver' => 'sqlite', 'database' => ':memory:']), static function () use (&$now): int {
        return $now;
    });
    $queue->install();
    $handler = new QueueWorkerRecorder();
    $container = new Container();
    $container->instance(DatabaseQueue::class, $queue);
    $container->instance(QueueWorkerRecorder::class, $handler);
    $app = new Application(__DIR__, new Config(['queue' => [
        'handlers' => ['test.record' => QueueWorkerRecorder::class], 'lease_seconds' => 10, 'retry_delay' => 5,
    ]]), $container);
    return [$queue, $handler, $app->container()];
}

$tests['handlers receive the stable ID and payload from the app container'] = static function (): void {
    $now = 100;
    [$queue, $handler, $container] = workerFixture($now);
    $worker = $container->get(Worker::class);
    $id = $queue->push('test.record', ['reference' => 7], delay: 2);
    workerCheck($worker->runNext() === 'empty');
    $now += 2;
    workerCheck($worker->runNext() === 'completed');
    workerCheck($handler->received === [[$id, ['reference' => 7]]] && $worker->runNext() === 'empty');
};
$tests['exceptions back off, exhaust the budget, and can be retried with the same ID'] = static function (): void {
    $now = 100;
    [$queue, $handler, $container] = workerFixture($now);
    $logger = new class extends AbstractLogger {
        public array $events = [];
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->events[] = [$level, $message, $context];
            throw new RuntimeException('Synthetic log failure');
        }
    };
    $container->instance(LoggerInterface::class, $logger);
    $worker = $container->get(Worker::class);
    $handler->action = static fn() => throw new RuntimeException('fixture-private-message');
    $id = $queue->push('test.record', ['fixture-private-key' => 'fixture-private-value'], maxAttempts: 2);
    workerCheck($worker->runNext() === 'released');
    workerCheck($worker->runNext() === 'empty');
    $now += 5;
    workerCheck($worker->runNext() === 'failed' && $worker->runNext() === 'empty');
    workerCheck($queue->failed() === [['id' => $id, 'name' => 'test.record', 'attempts' => 2]]);
    workerCheck(count($logger->events) === 2 && !str_contains(json_encode($logger->events, JSON_THROW_ON_ERROR), 'fixture-private'));
    $handler->action = null;
    workerCheck($queue->retry($id) && $worker->runNext() === 'completed');
    workerCheck(array_column($handler->received, 0) === [$id, $id, $id] && $queue->failed() === []);
};
$tests['an unregistered job name cannot select a handler from the container'] = static function (): void {
    $now = 100;
    [$queue, $handler, $container] = workerFixture($now);
    $queue->push(QueueWorkerRecorder::class, maxAttempts: 1);
    workerCheck($container->get(Worker::class)->runNext() === 'failed' && $handler->received === []);
};
$tests['lease loss after processing preserves the job for its next attempt'] = static function (): void {
    $now = 100;
    [$queue, $handler, $container] = workerFixture($now);
    $worker = $container->get(Worker::class);
    $id = $queue->push('test.record');
    $handler->action = static function () use (&$now): void { $now += 10; };
    workerCheck($worker->runNext() === 'lost');
    $handler->action = null;
    workerCheck($worker->runNext() === 'completed' && array_column($handler->received, 0) === [$id, $id]);
};
$tests['invalid worker configuration does not spend a job attempt'] = static function (): void {
    $now = 100;
    [$queue, $handler, $container] = workerFixture($now);
    $id = $queue->push('test.record');
    foreach ([[['bad' => stdClass::class], 10, 0], [[], 0, 0], [[], 10, -1]] as [$handlers, $lease, $delay]) {
        try {
            new Worker($queue, $container, $handlers, $lease, $delay);
            throw new RuntimeException('Invalid configuration accepted.');
        } catch (InvalidArgumentException) {
        }
    }
    $job = $queue->reserve();
    workerCheck($job->id === $id && $job->attempts === 1);
};

foreach ($tests as $description => $test) {
    try {
        $test();
    } catch (Throwable $error) {
        fwrite(STDERR, $description . ': ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
echo count($tests) . ' queue worker tests passed.' . PHP_EOL;
