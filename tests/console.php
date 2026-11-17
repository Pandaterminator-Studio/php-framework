<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Application;
use Core\Console\Kernel;
use Core\Console\Process;
use Core\Console\Scaffolder;
use Core\Http\Request;
use Core\Http\Response;

function consoleAssert(bool $condition, string $message = 'Console assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function consoleRun(array $arguments, string $directory): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/iris'], $arguments);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    consoleAssert(is_resource($process), 'Console subprocess could not be started.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return ['status' => $status, 'output' => $output, 'error' => $error];
}

function consoleProject(string $directory, bool $autoload = true): string
{
    $base = (new Scaffolder(dirname(__DIR__)))->project($directory . '/application-' . bin2hex(random_bytes(5)));
    if ($autoload) {
        mkdir($base . '/vendor');
        $source = '<?php require_once ' . var_export(dirname(__DIR__) . '/.iris/vendor/autoload.php', true) . ';'
            . 'spl_autoload_register(static function(string $class): void {'
            . 'if(str_starts_with($class,"App\\\\")) {'
            . '$file=dirname(__DIR__)."/app/".str_replace("\\\\","/",substr($class,4)).".php";'
            . 'if(is_file($file)){require_once $file;}}});';
        file_put_contents($base . '/vendor/autoload.php', $source);
    }
    return $base;
}

function removeConsoleFixtures(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            removeConsoleFixtures($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/iris-console-tests-' . bin2hex(random_bytes(8));
consoleAssert(mkdir($directory, 0700));
$tests = [];
$tests['help and version run outside an application'] = static function () use ($directory): void {
    foreach (['help', 'version'] as $command) {
        $result = consoleRun([$command], $directory);
        consoleAssert($result['status'] === 0 && str_contains($result['output'], 'Iris framework') && $result['error'] === '');
    }
};
$tests['unknown commands options and missing values return nonzero'] = static function () use ($directory): void {
    foreach ([['missing'], ['help', '--unknown'], ['help', '--app'], ['help', '--app=a', '--app=b']] as $arguments) {
        $result = consoleRun($arguments, $directory);
        consoleAssert($result['status'] !== 0 && $result['error'] !== '');
    }
};
$tests['new creates an empty framework application with actual path repository'] = static function () use ($directory): void {
    $target = $directory . '/new application';
    $result = consoleRun(['new', $target], $directory);
    consoleAssert($result['status'] === 0);
    $composer = json_decode(file_get_contents($target . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
    consoleAssert($composer['repositories'][0]['url'] === realpath(dirname(__DIR__)));
    consoleAssert($composer['require']['pandaterminator-studio/iris'] === '@dev' && $composer['autoload']['psr-4']['App\\'] === 'app/');
    consoleAssert(!is_dir($target . '/vendor') && !is_file($target . '/storage/database.sqlite'));
    consoleAssert(is_file($target . '/public/router.php') && is_file($target . '/public/.htaccess') && is_file($target . '/.gitignore'));
    $routes = require $target . '/routes/web.php';
    $router = new Core\Router();
    $routes($router);
    consoleAssert($router->registeredRoutes() === []);
};
$tests['generated projects can run their Composer HTTP test'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    $composer = json_decode(file_get_contents($base . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
    consoleAssert($composer['scripts']['test'] === '@php tests/http.php');
    ob_start();
    try {
        require $base . '/tests/http.php';
        consoleAssert(trim(ob_get_contents()) === 'Application HTTP test passed.');
    } finally {
        ob_end_clean();
    }
    $fresh = require $base . '/bootstrap.php';
    consoleAssert($fresh->router()->registeredRoutes() === []);
};
$tests['new refuses to overwrite existing files or nonempty directories'] = static function () use ($directory): void {
    $target = $directory . '/existing';
    mkdir($target);
    file_put_contents($target . '/original.txt', 'retained');
    consoleAssert(consoleRun(['new', $target], $directory)['status'] !== 0);
    consoleAssert(file_get_contents($target . '/original.txt') === 'retained' && count(scandir($target)) === 3);
    consoleAssert(consoleRun(['new', $target . '/original.txt'], $directory)['status'] !== 0);
};
$tests['new accepts an existing empty destination and nested parents'] = static function () use ($directory): void {
    $target = $directory . '/empty';
    mkdir($target);
    consoleAssert(consoleRun(['new', $target], $directory)['status'] === 0);
    consoleAssert(consoleRun(['new', $directory . '/nested/parent/project'], $directory)['status'] === 0);
};
$tests['new rejects symbolic links and control-character paths'] = static function () use ($directory): void {
    $target = $directory . '/link-target';
    mkdir($target);
    if (@symlink($target, $directory . '/project-link')) {
        consoleAssert(consoleRun(['new', $directory . '/project-link'], $directory)['status'] !== 0);
        consoleAssert(scandir($target) === ['.', '..']);
    }
    consoleAssert(consoleRun(['new', "bad\npath"], $directory)['status'] !== 0);
};
$tests['generated bootstrap returns an application without database traffic'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    $application = require $base . '/bootstrap.php';
    consoleAssert($application instanceof Application);
    consoleAssert($application->handle(new Request('GET', '/'))->status() === 404);
    consoleAssert(!is_file($base . '/storage/database.sqlite'));
};
$tests['route list prints only declared method path and name'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    file_put_contents($base . '/routes/web.php', '<?php return static function(\\Core\\Router $router): void {$router->get("/probe", static fn()=>\\Core\\Http\\Response::json([]))->name("probe.index");};');
    $result = consoleRun(['route:list', '--app', $base], $directory);
    consoleAssert($result['status'] === 0 && str_contains($result['output'], "GET|HEAD\t/probe\tprobe.index"));
    consoleAssert(!is_file($base . '/storage/database.sqlite'));
};
$tests['application commands fail clearly when bootstrap is absent'] = static function () use ($directory): void {
    $result = consoleRun(['route:list'], $directory);
    consoleAssert($result['status'] !== 0 && str_contains($result['error'], 'Iris application'));
};
$tests['controller and middleware generators produce usable PHP classes'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    consoleAssert(consoleRun(['make:controller', 'SyntheticController', '--app=' . $base], $directory)['status'] === 0);
    consoleAssert(consoleRun(['make:middleware', 'SyntheticMiddleware', '--app=' . $base], $directory)['status'] === 0);
    require $base . '/vendor/autoload.php';
    $controller = new App\Controllers\SyntheticController();
    consoleAssert($controller(new Request('GET'))->body() === '{"data":[]}');
    $middleware = new App\Middleware\SyntheticMiddleware();
    $response = $middleware->process(new Request('GET'), static fn() => Response::text('passed'));
    consoleAssert($response->body() === 'passed');
};
$tests['generator refuses existing controller without modifying it'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    consoleAssert(consoleRun(['make:controller', 'ExistingController', '--app=' . $base], $directory)['status'] === 0);
    $path = $base . '/app/Controllers/ExistingController.php';
    $original = file_get_contents($path);
    consoleAssert(consoleRun(['make:controller', 'ExistingController', '--app=' . $base], $directory)['status'] !== 0);
    consoleAssert(file_get_contents($path) === $original);
};
$tests['unsafe and PHP-reserved generator names are rejected'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    foreach (['../Escape', 'Bad/Name', 'Bad;Name', 'Case', 'Default', 'String', 'lowercase'] as $name) {
        consoleAssert(consoleRun(['make:controller', $name, '--app=' . $base], $directory)['status'] !== 0);
    }
    consoleAssert(scandir($base . '/app/Controllers') === ['.', '..', '.gitkeep']);
    consoleAssert(consoleRun(['make:migration', '../escape', '--app=' . $base], $directory)['status'] !== 0);
};
$tests['generators do not follow symbolic application directories'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    $outside = $directory . '/outside';
    mkdir($outside);
    unlink($base . '/app/Middleware/.gitkeep');
    rmdir($base . '/app/Middleware');
    if (@symlink($outside, $base . '/app/Middleware')) {
        consoleAssert(consoleRun(['make:middleware', 'OutsideMiddleware', '--app=' . $base], $directory)['status'] !== 0);
        consoleAssert(scandir($outside) === ['.', '..']);
    }
};
$tests['migration generator creates an empty reversible migration'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    $result = consoleRun(['make:migration', 'create_synthetic_table', '--app=' . $base], $directory);
    consoleAssert($result['status'] === 0);
    $files = glob($base . '/database/migrations/*.php');
    consoleAssert(count($files) === 1 && preg_match('/^[0-9]{14}_create_synthetic_table\.php$/D', basename($files[0])) === 1);
    $migration = require $files[0];
    consoleAssert($migration instanceof Core\Database\MigrationInterface && !str_contains(file_get_contents($files[0]), 'CREATE TABLE'));
};
$tests['migration commands apply report and reverse synthetic schema'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    $source = '<?php return new class implements \\Core\\Database\\MigrationInterface {'
        . 'public function up(\\Core\\Database\\Connection $connection):void{$connection->execute("CREATE TABLE console_fixture (id INTEGER PRIMARY KEY)");}'
        . 'public function down(\\Core\\Database\\Connection $connection):void{$connection->execute("DROP TABLE console_fixture");}};';
    file_put_contents($base . '/database/migrations/001_synthetic.php', $source);
    $pending = consoleRun(['migrate:status', '--app=' . $base], $directory);
    consoleAssert($pending['status'] === 0 && str_contains($pending['output'], "pending\t-\t001_synthetic.php"));
    consoleAssert(consoleRun(['migrate', '--app=' . $base], $directory)['status'] === 0);
    $applied = consoleRun(['migrate:status', '--app=' . $base], $directory);
    consoleAssert($applied['status'] === 0 && str_contains($applied['output'], "applied\t1\t001_synthetic.php"));
    $pdo = new PDO('sqlite:' . $base . '/storage/database.sqlite');
    consoleAssert($pdo->query("SELECT name FROM sqlite_master WHERE name='console_fixture'")->fetchColumn() === 'console_fixture');
    consoleAssert(consoleRun(['migrate:rollback', '--app=' . $base], $directory)['status'] === 0);
    consoleAssert($pdo->query("SELECT name FROM sqlite_master WHERE name='console_fixture'")->fetchColumn() === false);
};
$tests['migration failures exit nonzero without disclosing exception messages'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    file_put_contents($base . '/database/migrations/001_failure.php', '<?php return new class implements \\Core\\Database\\MigrationInterface { public function up(\\Core\\Database\\Connection $connection):void{throw new \\RuntimeException("synthetic-private-detail");}public function down(\\Core\\Database\\Connection $connection):void{}};');
    $result = consoleRun(['migrate', '--app=' . $base], $directory);
    consoleAssert($result['status'] === 1 && !str_contains($result['error'] . $result['output'], 'synthetic-private-detail'));
};
$tests['development server receives an argument array with the generated public router'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    $process = new class extends Process {
        public array $received = [];
        public function run(array $command, string $directory): int
        {
            $this->received = [$command, $directory];
            return 17;
        }
    };
    ob_start();
    $status = (new Kernel(dirname(__DIR__), $process))->run(['serve', '--app=' . $base, '--host=::1', '--port=8123'], $directory);
    ob_end_clean();
    consoleAssert($status === 17 && $process->received === [[PHP_BINARY, '-S', '[::1]:8123', '-t', $base . '/public', $base . '/public/router.php'], $base]);
};
$tests['development server rejects unsafe hosts ports and extra arguments'] = static function () use ($directory): void {
    $base = consoleProject($directory);
    foreach ([['--host=127.0.0.1;command'], ['--port=0'], ['--port=65536'], ['--port=abc'], ['extra'], ['--unknown=value']] as $arguments) {
        consoleAssert(consoleRun(array_merge(['serve', '--app=' . $base], $arguments), $directory)['status'] !== 0);
    }
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
    removeConsoleFixtures($directory);
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
}
fwrite(STDOUT, $passed . ' console tests passed; ' . count($failures) . ' failed.' . PHP_EOL);
exit($failures === [] ? 0 : 1);
