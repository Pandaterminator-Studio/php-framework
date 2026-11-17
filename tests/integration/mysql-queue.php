<?php

require dirname(__DIR__, 2) . '/.iris/vendor/autoload.php';

use Core\Database\Connection;
use Core\Queue\DatabaseQueue;

function mysqlCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mysqlFixtureConnection(?string $database = null): Connection
{
    $socket = getenv('IRIS_TEST_MYSQL_SOCKET');
    $port = getenv('IRIS_TEST_MYSQL_PORT');
    mysqlCheck(($socket !== false) !== ($port !== false), 'Set exactly one of IRIS_TEST_MYSQL_SOCKET or IRIS_TEST_MYSQL_PORT.');
    if ($socket !== false) {
        mysqlCheck(str_starts_with($socket, '/') && !str_contains($socket, ';'), 'Set an absolute MySQL socket path.');
        $dsn = 'mysql:unix_socket=' . $socket;
    } else {
        mysqlCheck(ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535, 'Invalid loopback MySQL port.');
        $dsn = 'mysql:host=127.0.0.1;port=' . $port;
    }
    if ($database !== null) {
        mysqlCheck(preg_match('/^iris_queue_test_[a-f0-9]{24}$/D', $database) === 1, 'Invalid test database name.');
        $dsn .= ';dbname=' . $database;
    }
    return new Connection([
        'dsn' => $dsn . ';charset=utf8mb4',
        'username' => getenv('IRIS_TEST_MYSQL_USER') ?: 'root',
        'password' => getenv('IRIS_TEST_MYSQL_PASSWORD') === false ? '' : getenv('IRIS_TEST_MYSQL_PASSWORD'),
    ]);
}

function mysqlClaim(string $database): void
{
    $connection = mysqlFixtureConnection($database);
    $connection->pdo();
    $queue = new DatabaseQueue($connection, static fn(): int => 1000);
    echo "ready\n";
    mysqlCheck(fgets(STDIN) === "claim\n", 'Missing start signal.');
    $job = $queue->reserve();
    mysqlCheck($job !== null && $job->attempts === 1, 'Claim expected.');
    echo $job->id . PHP_EOL;
    mysqlCheck(fgets(STDIN) === "finish\n" && $queue->complete($job), 'Current lease could not complete.');
    echo "done\n";
}

function mysqlWorkerLine($output): string
{
    $read = [$output];
    $unusedWrite = $unusedExcept = null;
    mysqlCheck(stream_select($read, $unusedWrite, $unusedExcept, 15) === 1, 'Timed out waiting for MySQL worker.');
    $line = fgets($output);
    mysqlCheck($line !== false, 'MySQL worker exited before sending a result.');
    return rtrim($line, "\r\n");
}

function mysqlConcurrentClaims(string $database, DatabaseQueue $queue): void
{
    $workers = [];
    $expected = [];
    try {
        for ($index = 0; $index < 4; $index++) {
            $expected[] = $queue->push('report.build');
            $process = proc_open([PHP_BINARY, '-r', 'require $argv[1]; mysqlClaim($argv[2]);', __FILE__, $database], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR,
            ], $pipes);
            mysqlCheck(is_resource($process), 'Could not launch MySQL queue worker.');
            $workers[] = [$process, $pipes[0], $pipes[1]];
            mysqlCheck(mysqlWorkerLine($pipes[1]) === 'ready', 'Worker did not connect.');
        }
        foreach ($workers as [, $input]) {
            fwrite($input, "claim\n");
        }
        $claimed = [];
        foreach ($workers as [, , $output]) {
            $claimed[] = mysqlWorkerLine($output);
        }
        sort($claimed);
        sort($expected);
        mysqlCheck($claimed === $expected, 'Simultaneous claims lost or duplicated a job.');
        foreach ($workers as [, $input]) {
            fwrite($input, "finish\n");
        }
        foreach ($workers as [$process, $input, $output]) {
            mysqlCheck(mysqlWorkerLine($output) === 'done', 'Worker could not acknowledge its job.');
            fclose($input);
            fclose($output);
            mysqlCheck(proc_close($process) === 0, 'Worker exited unsuccessfully.');
        }
        mysqlCheck($queue->reserve() === null, 'Completed work was available again.');
    } finally {
        foreach ($workers as [$process, $input, $output]) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            foreach ([$input, $output] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}

$database = 'iris_queue_test_' . bin2hex(random_bytes(12));
$admin = mysqlFixtureConnection();
$admin->execute('CREATE DATABASE ' . $database);
try {
    $connection = mysqlFixtureConnection($database);
    $time = 1000;
    $queue = new DatabaseQueue($connection, static function () use (&$time): int { return $time; });
    $queue->install();
    $queue->install();
    $rollback = new RuntimeException('Roll back fixture.');
    try {
        $connection->transaction(static function () use ($queue, $rollback): void {
            $queue->push('report.build');
            throw $rollback;
        });
    } catch (RuntimeException $exception) {
        mysqlCheck($exception === $rollback, 'Enqueue transaction failed unexpectedly.');
    }
    mysqlCheck($queue->reserve() === null, 'Rolled back enqueue was visible.');
    mysqlConcurrentClaims($database, $queue);

    $id = $queue->push('report.build', ['report' => 'café'], 0, 2);
    $stale = $queue->reserve(10);
    mysqlCheck($stale !== null && $stale->id === $id && $stale->payload === ['report' => 'café'], 'First claim did not round-trip.');
    $time = 1010;
    $current = $queue->reserve();
    mysqlCheck($current !== null && $current->id === $id && $current->attempts === 2, 'Expired lease was not recovered.');
    mysqlCheck(!$queue->complete($stale) && !$queue->release($stale), 'Stale worker modified the new lease.');
    mysqlCheck($queue->release($current) && $queue->reserve() === null, 'Exhausted job did not stop.');
    mysqlCheck($queue->failed() === [['id' => $id, 'name' => 'report.build', 'attempts' => 2]], 'Unexpected failed job metadata.');
    mysqlCheck($queue->retry($id) && !$queue->retry($id), 'Retry was not conditional on failed state.');
    $reset = $queue->reserve();
    mysqlCheck($reset !== null && $reset->attempts === 1 && $reset->id === $id && $queue->complete($reset), 'Retried job did not complete.');
    mysqlCheck($queue->reserve() === null && $queue->failed() === [], 'Unexpected job after recovery.');
} finally {
    $admin->execute('DROP DATABASE ' . $database);
}
echo "MySQL queue integration passed (rollback, concurrent claims, lease recovery and manual retry).\n";
