<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv, 1);
if (count($arguments) > 1 || ($arguments !== [] && !in_array($arguments[0], ['--lint', '--tests-only'], true))) {
    fwrite(STDERR, "Usage: php tests/run.php [--lint|--tests-only]\n");
    exit(2);
}
if (!function_exists('proc_open')) {
    fwrite(STDERR, "PHP proc_open is required to run isolated validation processes.\n");
    exit(1);
}

$root = dirname(__DIR__);
$mode = $arguments[0] ?? '';
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

function irisValidationProcess(array $command, string $directory, string $nullDevice, bool $capture = false): array
{
    $descriptors = [
        0 => ['file', $nullDevice, 'r'],
        1 => $capture ? ['pipe', 'w'] : STDOUT,
        2 => $capture ? ['redirect', 1] : STDERR,
    ];
    $process = proc_open($command, $descriptors, $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start a validation process.');
    }
    try {
        $output = $capture ? stream_get_contents($pipes[1]) : '';
        if ($capture) {
            fclose($pipes[1]);
        }
        $status = proc_close($process);
        return ['status' => $status, 'output' => $output];
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

try {
    if ($mode !== '--tests-only') {
        $sources = [];
        foreach (['Core', 'App', 'public', 'tests', 'skeleton', 'bin'] as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if ($file->isFile() && !$file->isLink() && (strtolower($file->getExtension()) === 'php' || $path === $root . '/bin/iris')
                    && !str_contains($path, '/vendor/') && !str_contains($path, '/.iris/')) {
                    $sources[] = $file->getPathname();
                }
            }
        }
        sort($sources, SORT_STRING);
        $lintFailures = 0;
        foreach ($sources as $source) {
            $result = irisValidationProcess([PHP_BINARY, '-l', $source], $root, $nullDevice, true);
            if ($result['status'] !== 0) {
                $lintFailures++;
                fwrite(STDERR, $result['output']);
            }
        }
        echo 'PHP syntax: ' . (count($sources) - $lintFailures) . '/' . count($sources) . ' files passed.' . PHP_EOL;
        if ($lintFailures !== 0) {
            exit(1);
        }
    }

    if ($mode === '--lint') {
        exit(0);
    }
    if (!is_file($root . '/.iris/vendor/autoload.php')) {
        fwrite(STDERR, "Install dependencies with composer install --no-plugins --no-scripts before running tests.\n");
        exit(1);
    }

    $suites = array_values(array_filter(glob(__DIR__ . '/*.php') ?: [], static fn(string $file): bool => $file !== __FILE__ && is_file($file) && !is_link($file)));
    sort($suites, SORT_STRING);
    if ($suites === []) {
        throw new RuntimeException('No regression test suites were found.');
    }
    $failures = 0;
    foreach ($suites as $suite) {
        echo PHP_EOL . 'Running tests/' . basename($suite) . PHP_EOL;
        $result = irisValidationProcess([PHP_BINARY, $suite], $root, $nullDevice);
        if ($result['status'] !== 0) {
            $failures++;
            fwrite(STDERR, 'Suite failed: tests/' . basename($suite) . PHP_EOL);
        }
    }
    echo PHP_EOL . 'Regression suites: ' . (count($suites) - $failures) . '/' . count($suites) . ' passed.' . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Validation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
