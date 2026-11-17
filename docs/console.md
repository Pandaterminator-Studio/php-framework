# Iris console

The console creates framework application files and runs local development or database commands. It does not create a finished website, install dependencies automatically, publish releases, or deploy servers.

From the framework source checkout:

```sh
php bin/iris help
php bin/iris version
php bin/iris new ../my-application
```

In a consuming application after Composer installation:

```sh
php vendor/bin/iris help
php vendor/bin/iris serve
```

Application commands use the current directory. Pass `--app=/absolute/path/to/application` or `--app /absolute/path/to/application` to select another application. Its regular `bootstrap.php` must return `Core\Application`. Bootstrapping executes trusted application PHP; never point the console at untrusted application sources.

## Commands

| Command | Behavior |
| --- | --- |
| `help` | List commands without loading an application. |
| `version` | Identify the development framework build; no release version is inferred. |
| `new PATH` | Create an empty application from the included skeleton. |
| `serve` | Run PHP's local development server on `127.0.0.1:8000`. |
| `route:list` | List registered HTTP methods, paths and route names. |
| `migrate` | Apply pending migration files. |
| `migrate:status` | Show migration batches, pending files and missing applied files. |
| `migrate:rollback` | Reverse the latest migration batch. |
| `make:migration NAME` | Generate an anonymous migration with empty `up()` and `down()` methods. |
| `make:controller NAME` | Generate an invokable controller returning `Response::json(['data' => []])`. |
| `make:middleware NAME` | Generate a pass-through `MiddlewareInterface` implementation. |
| `make:job NAME` | Create an application job handler in `app/Jobs/`. |
| `queue:install` | Create the database queue table and indexes in the configured database. Safe to repeat. |
| `queue:work [--limit=N]` | Run up to N job attempts, stopping as soon as the queue has no job ready. |
| `queue:failed` | Show IDs, names and attempt counts for up to 50 failed jobs. |
| `queue:retry ID` | Return one failed job to the queue with a fresh attempt budget. |

## Create an application

```sh
php bin/iris new ../my-application
cd ../my-application
composer install --no-plugins --no-scripts
php vendor/bin/iris route:list
php vendor/bin/iris serve
```

The destination must not exist or must be an empty regular directory. Existing files and nonempty directories are refused. Nested parent directories can be created. Existing destination symlinks are rejected. Composer dependencies are installed only by the explicit Composer command.

The generated Composer project references this local framework source using an absolute path repository and a development requirement. This makes local source installation possible without claiming the package has been published. Update that path when moving the source checkout. See [installation](installation.md).

## Development server

```sh
php vendor/bin/iris serve --host=127.0.0.1 --port=8123
```

The host must be `localhost` or an IP address; IPv6 is accepted. Ports must be integers from 1 to 65535. The process starts through an argument array, without a shell. It serves only the application's `public/` document root and uses its `public/router.php`. Stop it with Ctrl+C. PHP's built-in server is for local development; use a configured web server and appropriate process management for production.

The empty application has no registered routes, so its root URL returns 404 until application routes are added. It does not create or seed business data when opened.

## Generate source files

```sh
php vendor/bin/iris make:controller InventoryController
php vendor/bin/iris make:middleware RequestContextMiddleware
php vendor/bin/iris make:migration create_inventory_tables
```

Controllers are written to `app/Controllers/`; middleware to `app/Middleware/`. Class names must start with an uppercase letter and contain only letters and numbers. PHP reserved names and path segments are rejected. PHP's tokenizer extension is required to validate generated class syntax. The application `App\` namespace is configured through Composer PSR-4 autoloading.

Migration names start with a lowercase letter and contain lowercase letters, numbers and underscores. Files are created in `database/migrations/` with a UTC `YYYYMMDDHHMMSS_` prefix. Generation never overwrites an existing file; a same-name timestamp collision must be retried later or given a different name. The generated methods are empty: implement and review schema changes before running migrations. Generator directories cannot be symlinks.

Generated controllers and middleware are not registered automatically. Add the desired routes and middleware explicitly through the application/router APIs.

## Background jobs

```sh
php vendor/bin/iris make:job GenerateReport
php vendor/bin/iris queue:install
php vendor/bin/iris queue:work --limit=25
php vendor/bin/iris queue:failed
php vendor/bin/iris queue:retry "$job_id"
```

Implement `handle(array $payload, string $jobId): void` in the generated class, then map an application-defined job name to the handler class in `config/queue.php` under `handlers`. The generated stub throws until implemented. See [background jobs](queues.md) for configuration and dispatch.

Run `queue:install` once for each database that holds jobs. The worker's default limit is one attempt. Specify a whole number from 1 to 1000 for a larger batch. The worker exits immediately when no job is ready, including when the remaining jobs are delayed. Run the command again to process later jobs.

The worker prints four counts. `completed` records acknowledged jobs, `released` counts attempts scheduled for retry, `failed` counts jobs that exhausted their attempt budget, and `lost` counts attempts whose reservation expired or changed before the result could be saved. Exit status 0 means the worker completed its work or found the queue empty. It returns 1 if any attempt was released, failed or lost, or if the command encountered an application or database error. An invalid option returns 2.

Set `job_id` to a 32-character lowercase hexadecimal ID from `queue:failed` before running the retry command. It returns 1 if the ID does not identify a failed job. A retry keeps the original job ID.

## Database commands

Database commands resolve the application's configured `DatabaseManager` default connection. The migration directory comes from `database.migrations`, falling back to `database/migrations/` under the application root. Relative configured migration paths are resolved under that root. `migrate:status` initializes migration metadata and can create the configured SQLite database file.

These commands change the selected database when requested; they do not prompt, make backups or infer a deployment policy. Confirm your selected application and database configuration before running them against production. Consult [database behavior and migration recovery](database.md), especially MySQL's implicit commits and partial schema changes.

Successful commands return exit status 0. Usage errors return 2. Application/bootstrap/database failures return 1 and a generic error message. Arbitrary exception messages, credentials and PDO connection details are not printed by the console. Configure application diagnostics separately; the console does not create an error-log file automatically.
