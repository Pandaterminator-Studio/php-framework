<?php

namespace Core\Console;

use Core\Application;
use Core\Database\DatabaseManager;
use Core\Database\Migrator;
use Core\Queue\DatabaseQueue;
use Core\Queue\Worker;
use Throwable;

final class Kernel
{
    private string $frameworkPath;
    private Process $process;

    public function __construct(?string $frameworkPath = null, ?Process $process = null)
    {
        $this->frameworkPath = realpath($frameworkPath ?? dirname(__DIR__, 2)) ?: '';
        $this->process = $process ?? new Process();
    }

    public function run(array $arguments, ?string $workingDirectory = null): int
    {
        try {
            if (!array_is_list($arguments)) {
                throw new ConsoleException('Command arguments must be a list.');
            }
            $command = array_shift($arguments) ?? 'help';
            if (!is_string($command) || array_filter($arguments, static fn($argument): bool => !is_string($argument))) {
                throw new ConsoleException('Command arguments must be strings.');
            }
            $directory = $workingDirectory ?? getcwd();
            if (!is_string($directory) || !is_dir($directory)) {
                throw new ConsoleException('The working directory is unavailable.');
            }
            [$arguments, $options] = $this->options($arguments);
            if (in_array($command, ['help', '--help', '-h'], true)) {
                $this->noArguments($arguments, $options);
                $this->help();
                return 0;
            }
            if (in_array($command, ['version', '--version', '-V'], true)) {
                $this->noArguments($arguments, $options);
                echo "Iris framework (development)\n";
                return 0;
            }
            if ($command === 'new') {
                if (count($arguments) !== 1 || $options !== []) {
                    throw new ConsoleException('Usage: iris new PATH');
                }
                $target = $this->absolute($arguments[0], $directory);
                $created = (new Scaffolder($this->frameworkPath))->project($target);
                echo 'Created application at ' . $created . PHP_EOL;
                echo "Run composer install --no-plugins --no-scripts in the application directory.\n";
                return 0;
            }
            if (!in_array($command, [
                'serve', 'route:list', 'migrate', 'migrate:status', 'migrate:rollback',
                'make:migration', 'make:controller', 'make:middleware', 'make:job',
                'queue:install', 'queue:work', 'queue:failed', 'queue:retry',
            ], true)) {
                throw new ConsoleException('Unknown command. Run iris help to see available commands.');
            }
            $base = $this->projectDirectory($options['app'] ?? $directory, $directory);
            unset($options['app']);
            if ($command === 'serve') {
                $this->noArguments($arguments, array_diff_key($options, ['host' => true, 'port' => true]));
                return $this->serve($base, $options);
            }
            if (str_starts_with($command, 'make:')) {
                if (count($arguments) !== 1 || $options !== []) {
                    throw new ConsoleException('Generation commands require one name and optionally --app=PATH.');
                }
                $scaffolder = new Scaffolder($this->frameworkPath);
                $path = match ($command) {
                    'make:controller' => $scaffolder->controller($base, $arguments[0]),
                    'make:middleware' => $scaffolder->middleware($base, $arguments[0]),
                    'make:job' => $scaffolder->job($base, $arguments[0]),
                    'make:migration' => $scaffolder->migration($base, $arguments[0]),
                };
                echo 'Created ' . substr($path, strlen($base) + 1) . PHP_EOL;
                return 0;
            }
            if (str_starts_with($command, 'queue:')) {
                return $this->queue($command, $base, $arguments, $options);
            }
            $this->noArguments($arguments, $options);
            $application = $this->bootstrap($base);
            if ($command === 'route:list') {
                echo "METHODS\tPATH\tNAME\n";
                foreach ($application->router()->registeredRoutes() as $route) {
                    echo implode('|', $route->methods()) . "\t/" . $route->path() . "\t" . ($route->routeName() ?? '-') . PHP_EOL;
                }
                return 0;
            }
            $migrationDirectory = $application->config()->get('database.migrations', $base . '/database/migrations');
            if (!is_string($migrationDirectory)) {
                throw new ConsoleException('Configure a migration directory path.');
            }
            $migrationDirectory = $this->absolute($migrationDirectory, $base);
            $manager = $application->container()->get(DatabaseManager::class);
            $migrator = new Migrator($manager->defaultConnection(), $migrationDirectory);
            if ($command === 'migrate:status') {
                echo "STATE\tBATCH\tMIGRATION\n";
                foreach ($migrator->status() as $row) {
                    echo ($row['applied'] ? 'applied' : 'pending') . ($row['available'] ? '' : ':missing') . "\t" . ($row['batch'] ?? '-') . "\t" . $row['migration'] . PHP_EOL;
                }
                return 0;
            }
            $changed = $command === 'migrate' ? $migrator->migrate() : $migrator->rollback();
            foreach ($changed as $migration) {
                echo ($command === 'migrate' ? 'Applied ' : 'Rolled back ') . $migration . PHP_EOL;
            }
            if ($changed === []) {
                echo "No migration changes.\n";
            }
            return 0;
        } catch (ConsoleException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 2;
        } catch (Throwable $exception) {
            fwrite(STDERR, "The command failed. Check the application configuration and server logs.\n");
            return 1;
        }
    }

    private function bootstrap(string $base): Application
    {
        $application = require $base . '/bootstrap.php';
        if (!$application instanceof Application) {
            throw new ConsoleException('Application bootstrap.php must return a Core\\Application instance.');
        }
        return $application;
    }

    private function queue(string $command, string $base, array $arguments, array $options): int
    {
        $limit = 1;
        if ($command === 'queue:work') {
            $this->noArguments($arguments, array_diff_key($options, ['limit' => true]));
            $value = $options['limit'] ?? '1';
            if (!preg_match('/^[1-9][0-9]{0,3}$/D', $value) || (int) $value > 1000) {
                throw new ConsoleException('Worker limit must be an integer between 1 and 1000.');
            }
            $limit = (int) $value;
        } elseif ($command === 'queue:retry') {
            if ($options !== [] || count($arguments) !== 1 || !preg_match('/^[0-9a-f]{32}$/D', $arguments[0])) {
                throw new ConsoleException('Usage: iris queue:retry ID (32 lowercase hexadecimal characters).');
            }
        } else {
            $this->noArguments($arguments, $options);
        }

        try {
            $container = $this->bootstrap($base)->container();
            if ($command === 'queue:work') {
                return $this->work($container->get(Worker::class), $limit);
            }
            $queue = $container->get(DatabaseQueue::class);
            if ($command === 'queue:install') {
                $queue->install();
                echo "Queue storage is ready.\n";
            } elseif ($command === 'queue:failed') {
                $rows = $queue->failed(50);
                echo "ID\tNAME\tATTEMPTS\n";
                foreach ($rows as $row) {
                    $id = preg_match('/^[0-9a-f]{32}$/D', $row['id']) ? $row['id'] : '[invalid]';
                    $name = DatabaseQueue::validName($row['name']) ? $row['name'] : '[invalid]';
                    printf("%s\t%s\t%d\n", $id, $name, $row['attempts']);
                }
            } elseif ($queue->retry($arguments[0])) {
                echo "Job queued for retry.\n";
            } else {
                fwrite(STDERR, "No failed job matched that ID.\n");
                return 1;
            }
            return 0;
        } catch (Throwable) {
            fwrite(STDERR, "Queue command failed. Check the application configuration, queue storage and server logs.\n");
            return 1;
        }
    }

    private function work(Worker $worker, int $limit): int
    {
        $counts = ['completed' => 0, 'released' => 0, 'failed' => 0, 'lost' => 0];
        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $outcome = $worker->runNext();
            if ($outcome === 'empty') {
                break;
            }
            if (!array_key_exists($outcome, $counts)) {
                throw new \UnexpectedValueException('Unknown worker outcome.');
            }
            $counts[$outcome]++;
        }
        printf("completed=%d released=%d failed=%d lost=%d\n", $counts['completed'], $counts['released'], $counts['failed'], $counts['lost']);
        return $counts['released'] + $counts['failed'] + $counts['lost'] > 0 ? 1 : 0;
    }

    private function serve(string $base, array $options): int
    {
        $host = $options['host'] ?? '127.0.0.1';
        $port = $options['port'] ?? '8000';
        if ($host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new ConsoleException('Development host must be localhost or an IP address.');
        }
        if (!ctype_digit($port) || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new ConsoleException('Development port must be between 1 and 65535.');
        }
        $public = $base . '/public';
        foreach (['index.php', 'router.php'] as $file) {
            if (is_link($public . '/' . $file) || !is_file($public . '/' . $file)) {
                throw new ConsoleException('The application requires regular public/index.php and public/router.php files.');
            }
        }
        $address = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        echo 'Development server: http://' . $address . PHP_EOL;
        return $this->process->run([PHP_BINARY, '-S', $address, '-t', $public, $public . '/router.php'], $base);
    }

    private function options(array $arguments): array
    {
        $options = [];
        $positionals = [];
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if (!str_starts_with($argument, '--')) {
                $positionals[] = $argument;
                continue;
            }
            $parts = explode('=', substr($argument, 2), 2);
            $name = $parts[0];
            if (!in_array($name, ['app', 'host', 'port', 'limit'], true) || isset($options[$name])) {
                throw new ConsoleException('Unknown or duplicate command option.');
            }
            $value = $parts[1] ?? ($arguments[++$index] ?? null);
            if (!is_string($value) || $value === '' || str_starts_with($value, '--') || str_contains($value, "\0")) {
                throw new ConsoleException('Command options require a value.');
            }
            $options[$name] = $value;
        }
        return [$positionals, $options];
    }

    private function absolute(string $path, string $base): string
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new ConsoleException('A non-empty path is required.');
        }
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) ? $path : $base . '/' . $path;
    }

    private function projectDirectory(string $path, string $workingDirectory): string
    {
        $base = realpath($this->absolute($path, $workingDirectory));
        if ($base === false || !is_dir($base) || !is_file($base . '/bootstrap.php') || is_link($base . '/bootstrap.php')) {
            throw new ConsoleException('Run the command in an Iris application or pass --app=PATH.');
        }
        return $base;
    }

    private function noArguments(array $arguments, array $options): void
    {
        if ($arguments !== [] || $options !== []) {
            throw new ConsoleException('Unexpected command arguments or options.');
        }
    }

    private function help(): void
    {
        echo "Iris framework CLI\n\n";
        echo "  help                         Show available commands\n";
        echo "  version                      Show framework version\n";
        echo "  new PATH                     Create an empty application\n";
        echo "  serve [--host=IP] [--port=N]  Start the local development server\n";
        echo "  route:list                   List registered application routes\n";
        echo "  migrate                      Apply pending migrations\n";
        echo "  migrate:status               Show migration state\n";
        echo "  migrate:rollback             Roll back the latest migration batch\n";
        echo "  make:migration NAME          Create an empty migration\n";
        echo "  make:controller NAME         Create an invokable controller\n";
        echo "  make:middleware NAME         Create pass-through middleware\n";
        echo "  make:job NAME                Create a background job handler\n";
        echo "  queue:install                Set up queue storage\n";
        echo "  queue:work [--limit=N]       Process up to N attempts (default 1)\n";
        echo "  queue:failed                 List up to 50 failed jobs\n";
        echo "  queue:retry ID               Make a failed job available again\n\n";
        echo "Application commands accept --app=PATH. serve is for development.\n";
    }
}
