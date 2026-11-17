# Background jobs

Iris can store background jobs in an application’s SQLite or MySQL database. An application chooses when to create the queue table and when to run workers. Creating an application or serving a request starts no worker.

## Register a handler

Run `php vendor/bin/iris make:job NormalizeTag` in the application. Replace the generated method with your handler. This example assumes the application owns a `tags` table with columns `id`, `label` and `normalized`.

```php
<?php

namespace App\Jobs;

use Core\Database\Connection;
use Core\Queue\JobHandler;
use Core\Validation\Validator;

final class NormalizeTag implements JobHandler
{
    public function __construct(private Connection $database) {}

    public function handle(array $payload, string $jobId): void
    {
        $fields = Validator::validate($payload, ['id' => 'required|integer|min:1']);
        $this->database->execute('UPDATE tags SET normalized = LOWER(label) WHERE id = ?', [$fields['id']]);
    }
}
```

Register the handler in the application's `config/queue.php`. Existing applications can add this file. The worker resolves the configured classes through the application's service container.

```php
<?php

return [
    'handlers' => ['tags.normalize' => App\Jobs\NormalizeTag::class],
    'lease_seconds' => 60,
    'retry_delay' => 5,
];
```

Choose a stable job name when renaming PHP classes. Queued jobs keep their original names. A missing handler counts as a failed attempt.

## Publish and consume

Run `php vendor/bin/iris queue:install` once for the selected application database. Repeating the command preserves existing jobs. The default queue uses the same injected `Connection` as the application.

```php
use Core\Queue\DatabaseQueue;

$queue = $app->container()->get(DatabaseQueue::class);
$jobId = $queue->push('tags.normalize', ['id' => 42], delay: 0, maxAttempts: 3);
```

`push()` returns a stable job ID. Inside `Connection::transaction()`, a push on the same connection commits or rolls back with the application's changes. This lets a handler see committed business data. Payloads contain plain JSON-compatible arrays and scalar values, up to 64 KiB. Store the identifiers the handler needs. A worker can then load the current records when it runs.

```sh
php vendor/bin/iris queue:work --limit=25
php vendor/bin/iris queue:failed
php vendor/bin/iris queue:retry "$job_id"
```

`queue:work` processes one attempt by default. It exits when the limit is reached or no job is ready. Invoke it periodically using your application's scheduler to process delayed and retried jobs. The limit counts attempts. With a retry delay of zero a single invocation can try the same job several times. See the [CLI guide](console.md) for options and exit codes.

## Recovery and delivery

A reservation gives a worker a time-limited lease and consumes one attempt. The handler runs outside the reservation transaction. On success Iris deletes the job. On exception Iris delays it until the next attempt or records it as failed when the budget is exhausted. If a process stops during a job, another worker can reclaim it after its lease expires. Only the current lease holder can acknowledge or release the job before expiry.

Processing can repeat after a crash or if a handler takes longer than its lease. Set `lease_seconds` above the handler’s expected maximum runtime and synchronize clocks on machines sharing the queue. A lease does not interrupt a running PHP handler. Make side effects idempotent. The example recalculates the same field from the current database row. For an external service that accepts an idempotency key, use the stable `$jobId`. Distinct calls to `push()` create distinct IDs.

`queue:failed` lists up to 50 failed jobs with IDs, names and attempt counts. Failed jobs retain their payload so an operator can retry them. After fixing a handler, retry its ID to reset the attempt count. The worker logs handler failures through the configured PSR-3 logger with job metadata. Payloads, lease tokens and exception messages are excluded from those logs.
