<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Database\Connection;
use Core\Queue\DatabaseQueue;

function queueCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function queueWait(callable $ready, string $description): void
{
    $deadline = hrtime(true) + 15_000_000_000;
    while (!$ready()) {
        queueCheck(hrtime(true) < $deadline, 'Timed out waiting for ' . $description);
        usleep(5_000);
        clearstatcache();
    }
}

function fixtureQueue(string $directory, int &$time): DatabaseQueue
{
    $database = new Connection(['driver' => 'sqlite', 'database' => $directory . '/jobs.sqlite']);
    return new DatabaseQueue($database, static function () use (&$time): int { return $time; });
}

function launchQueueWorker(string $mode, string $directory, int $index)
{
    $process = proc_open([PHP_BINARY, __FILE__, $mode, $directory, (string) $index], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $directory . "/output-$index", 'w'],
        2 => ['file', $directory . "/error-$index", 'w'],
    ], $pipes);
    queueCheck(is_resource($process), 'Cannot launch queue worker.');
    return $process;
}

function finishQueueWorker($process, string $directory, int $index, int $expectedStatus): void
{
    queueWait(static fn() => !proc_get_status($process)['running'], 'worker exit');
    $status = proc_close($process);
    queueCheck($status === $expectedStatus, "Worker $index returned $status. " . file_get_contents($directory . "/error-$index"));
}

function waitForConsumers(array $workers, string $directory, string $stage): void
{
    queueWait(static function () use ($workers, $directory, $stage): bool {
        $ready = true;
        foreach ($workers as $index => $process) {
            if (!proc_get_status($process)['running']) {
                throw new RuntimeException("Worker $index exited before $stage. " . file_get_contents($directory . "/error-$index"));
            }
            $ready = $ready && is_file($directory . "/$stage-$index");
        }
        return $ready;
    }, "all consumers to reach $stage");
}

if (isset($argv[1])) {
    [, $mode, $directory, $index] = $argv;
    $time = $mode === 'abandon' ? 1010 : 1000;
    $queue = fixtureQueue($directory, $time);
    if ($mode === 'abandon') {
        $job = $queue->reserve(10);
        queueCheck($job !== null && $job->attempts === 2, 'Could not reclaim the expired lease.');
        echo $job->id;
        exit(17);
    }

    touch($directory . "/ready-$index");
    queueWait(static fn() => is_file($directory . '/start'), 'consumer start');
    $job = $queue->reserve(60);
    queueCheck($job !== null, 'An eligible job was left unclaimed.');
    touch($directory . "/claimed-$index");
    queueWait(static fn() => is_file($directory . '/finish'), 'other consumers to hold their first lease');
    $completed = [];
    do {
        queueCheck($job->attempts === 1 && $queue->complete($job), 'Claim was repeated or lost before expiry.');
        $completed[] = $job->id;
        $job = $queue->reserve(60);
    } while ($job !== null);
    echo json_encode($completed, JSON_THROW_ON_ERROR);
    exit(0);
}

$directory = sys_get_temp_dir() . '/iris-queue-workers-' . bin2hex(random_bytes(8));
queueCheck(mkdir($directory, 0700), 'Cannot create the SQLite fixture directory.');
$workers = [];
try {
    $time = 1000;
    $queue = fixtureQueue($directory, $time);
    $queue->install();
    $expected = [];
    for ($number = 0; $number < 32; $number++) {
        $expected[] = $queue->push('report.build', ['number' => $number]);
    }
    $consumerCount = 4;
    for ($index = 0; $index < $consumerCount; $index++) {
        $workers[$index] = launchQueueWorker('drain', $directory, $index);
    }
    waitForConsumers($workers, $directory, 'ready');
    touch($directory . '/start');
    waitForConsumers($workers, $directory, 'claimed');
    touch($directory . '/finish');

    $completed = [];
    foreach ($workers as $index => $process) {
        finishQueueWorker($process, $directory, $index, 0);
        $jobs = json_decode(file_get_contents($directory . "/output-$index"), true, 32, JSON_THROW_ON_ERROR);
        array_push($completed, ...$jobs);
    }
    sort($expected);
    sort($completed);
    queueCheck($completed === $expected && $queue->reserve() === null, 'The consumers lost or duplicated a job.');
    queueCheck($queue->failed() === [], 'A competing consumer caused a job failure.');

    $id = $queue->push('report.build', [], 0, 3);
    $stale = $queue->reserve(10);
    queueCheck($stale !== null && $stale->id === $id && $stale->attempts === 1, 'Missing first lease.');
    $workers[$consumerCount] = launchQueueWorker('abandon', $directory, $consumerCount);
    finishQueueWorker($workers[$consumerCount], $directory, $consumerCount, 17);
    queueCheck(file_get_contents($directory . "/output-$consumerCount") === $id, 'Worker exited without claiming this job.');
    $time = 1019;
    queueCheck(!$queue->complete($stale) && !$queue->release($stale), 'Stale owner changed a newer lease.');
    queueCheck($queue->reserve(10) === null, 'Abandoned job became available before lease expiry.');
    $time = 1020;
    $recovered = $queue->reserve(10);
    queueCheck($recovered !== null && $recovered->id === $id && $recovered->attempts === 3, 'Abandoned job was not recovered at expiry.');
    queueCheck(!$queue->complete($stale) && $queue->complete($recovered), 'Only the current lease may complete a job.');
    queueCheck($queue->reserve() === null && $queue->failed() === [], 'Unexpected work after successful recovery.');
    echo "2 queue concurrency tests passed.\n";
} finally {
    foreach ($workers as $process) {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }
    unset($queue);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
