<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Console\Scaffolder;
use Core\Database\Connection;
use Core\Queue\DatabaseQueue;
use Core\Queue\JobHandler;

function runQueueCommand(array $arguments, string $directory): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/bin/iris', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $directory
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Starting the console failed.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), trim($output)];
}

function checkQueueConsole(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeQueueProject(string $directory): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

$root = sys_get_temp_dir() . '/iris-queue-console-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$passed = 0;
try {
    $base = (new Scaffolder(dirname(__DIR__)))->project($root . '/test app');
    file_put_contents($base . '/bootstrap.php', '<?php touch(__DIR__ . "/booted"); throw new RuntimeException("private-bootstrap-detail");');
    [$status, $help] = runQueueCommand(['help'], $root);
    foreach (['queue:install', 'queue:work', 'queue:failed', 'queue:retry', 'make:job'] as $command) {
        checkQueueConsole($status === 0 && str_contains($help, $command), 'Queue help is incomplete.');
    }
    $passed++;

    foreach ([
        ['queue:work', '--limit=0'], ['queue:work', '--limit=1001'], ['queue:work', '--limit=1.5'],
        ['queue:work', '--limit=4', '--limit=2'], ['queue:work', '--port=8000'], ['queue:work', 'extra'],
        ['queue:retry'], ['queue:retry', str_repeat('A', 32)], ['queue:retry', '123'],
        ['queue:retry', str_repeat('0', 32), 'extra'], ['queue:install', '--limit=2'], ['queue:failed', 'extra'],
    ] as $arguments) {
        [$status] = runQueueCommand([...$arguments, '--app', $base], $root);
        checkQueueConsole($status === 2 && !file_exists($base . '/booted'), 'Invalid command reached the application.');
    }
    $passed++;

    [$status, $message] = runQueueCommand(['queue:install'], $base);
    checkQueueConsole($status === 1 && !str_contains($message, 'private-bootstrap-detail'), 'Console exposed a bootstrap failure.');
    $passed++;

    checkQueueConsole(runQueueCommand(['make:job', 'RecordJob', '--app=' . $base], $root)[0] === 0, 'Job generation failed.');
    $generated = $base . '/app/Jobs/RecordJob.php';
    require $generated;
    $handler = new App\Jobs\RecordJob();
    checkQueueConsole($handler instanceof JobHandler, 'Generated class does not implement JobHandler.');
    try {
        $handler->handle([], str_repeat('0', 32));
        throw new RuntimeException('Generated job silently succeeded.');
    } catch (LogicException) {
    }
    $original = file_get_contents($generated);
    checkQueueConsole(runQueueCommand(['make:job', 'RecordJob'], $base)[0] === 2 && file_get_contents($generated) === $original, 'Generator overwrote a job.');
    checkQueueConsole(runQueueCommand(['make:job', '../Escape'], $base)[0] === 2, 'Generator accepted a path.');
    $passed++;

    $autoload = var_export(dirname(__DIR__) . '/.iris/vendor/autoload.php', true);
    file_put_contents($base . '/bootstrap.php', '<?php require_once ' . $autoload . '; require_once __DIR__ . "/app/Jobs/RecordJob.php"; return Core\Application::create(__DIR__);');
    file_put_contents($base . '/config/queue.php', "<?php return ['handlers' => ['record' => App\\Jobs\\RecordJob::class, 'broken' => App\\Jobs\\RecordJob::class]];");
    file_put_contents($generated, <<<'PHP'
<?php
namespace App\Jobs;

use Core\Database\Connection;
use Core\Queue\JobHandler;

final class RecordJob implements JobHandler
{
    public function __construct(private Connection $connection) {}

    public function handle(array $payload, string $jobId): void
    {
        if (isset($payload['fail']) && !file_exists(dirname(__DIR__, 2) . '/recovered')) {
            throw new \RuntimeException('private-handler-detail');
        }
        $this->connection->execute('INSERT INTO receipts (job_id) VALUES (?)', [$jobId]);
    }
}
PHP);
    foreach ([1, 2] as $iteration) {
        checkQueueConsole(runQueueCommand(['queue:install', '--app', $base], $root)[0] === 0, 'Queue storage could not be installed idempotently.');
    }
    checkQueueConsole(runQueueCommand(['queue:work', '--limit', '1000'], $base) === [0, 'completed=0 released=0 failed=0 lost=0'], 'Empty worker failed.');
    $passed++;

    $connection = new Connection(['driver' => 'sqlite', 'database' => $base . '/storage/database.sqlite']);
    $connection->execute('CREATE TABLE receipts (job_id TEXT PRIMARY KEY)');
    $queue = new DatabaseQueue($connection);
    foreach ([1, 2, 3] as $sequence) {
        $queue->push('record', ['sequence' => $sequence]);
    }
    checkQueueConsole(runQueueCommand(['queue:work'], $base) === [0, 'completed=1 released=0 failed=0 lost=0'], 'Default work limit failed.');
    checkQueueConsole(runQueueCommand(['queue:work', '--limit=25', '--app=' . $base], $root) === [0, 'completed=2 released=0 failed=0 lost=0'], 'Batched worker failed.');
    checkQueueConsole((int) $connection->first('SELECT COUNT(*) AS total FROM receipts')['total'] === 3, 'Completed jobs did not each run once.');
    $passed++;

    $jobId = $queue->push('broken', ['fail' => 'private-payload-detail'], maxAttempts: 1);
    checkQueueConsole(runQueueCommand(['queue:work'], $base) === [1, 'completed=0 released=0 failed=1 lost=0'], 'Failed job status or diagnostics incorrect.');
    checkQueueConsole(runQueueCommand(['queue:failed'], $base) === [0, "ID\tNAME\tATTEMPTS\n" . $jobId . "\tbroken\t1"], 'Failed-job listing disclosed additional fields.');
    $connection->execute('UPDATE iris_jobs SET name = ? WHERE id = ?', ["two\nlines", $jobId]);
    checkQueueConsole(runQueueCommand(['queue:failed'], $base) === [0, "ID\tNAME\tATTEMPTS\n" . $jobId . "\t[invalid]\t1"], 'Invalid database metadata disrupted the listing.');
    $connection->execute('UPDATE iris_jobs SET name = ? WHERE id = ?', ['broken', $jobId]);
    checkQueueConsole(runQueueCommand(['queue:retry', str_repeat('0', 32)], $base)[0] === 1, 'Retry reported success for a missing job.');
    touch($base . '/recovered');
    checkQueueConsole(runQueueCommand(['queue:retry', $jobId, '--app=' . $base], $root)[0] === 0, 'Failed job could not be retried.');
    checkQueueConsole(runQueueCommand(['queue:retry', $jobId], $base)[0] === 1, 'Repeated retry of pending job succeeded.');
    checkQueueConsole(runQueueCommand(['queue:work'], $base) === [0, 'completed=1 released=0 failed=0 lost=0'], 'Retried job failed.');
    checkQueueConsole($connection->first('SELECT job_id FROM receipts WHERE job_id = ?', [$jobId])['job_id'] === $jobId, 'Retried job lost its stable ID.');
    checkQueueConsole(runQueueCommand(['queue:failed'], $base) === [0, "ID\tNAME\tATTEMPTS"], 'Recovered job remained in failure listing.');
    $passed++;

    unlink($base . '/recovered');
    $queue->push('broken', ['fail' => 'private-payload-detail'], maxAttempts: 2);
    checkQueueConsole(runQueueCommand(['queue:work'], $base) === [1, 'completed=0 released=1 failed=0 lost=0'], 'Released job should set nonzero worker status.');
    $passed++;
} finally {
    removeQueueProject($root);
}

echo $passed . " queue console tests passed.\n";
