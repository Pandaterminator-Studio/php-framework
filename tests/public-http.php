<?php

function ensurePublicHttp(bool $condition, string $message = 'Public HTTP assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publicHttpRequest(string $base, string $path, string $method = 'GET'): array
{
    $handle = curl_init($base . $path);
    $headers = [];
    try {
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $headers[] = strtolower(trim($line));
                return strlen($line);
            },
        ]);
        $response = curl_exec($handle);
        ensurePublicHttp(is_string($response), 'Local public HTTP request failed.');
        return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response, 'headers' => $headers];
    } finally {
        curl_close($handle);
    }
}

function publicHttpAddress(): string
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    ensurePublicHttp(is_resource($listener), 'Cannot reserve a public HTTP fixture port.');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    ensurePublicHttp(is_string($address), 'Cannot determine public HTTP fixture port.');
    return $address;
}

function startPublicHttpProcess(array $command, string $address, string $log): mixed
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__));
    ensurePublicHttp(is_resource($process), 'Cannot start isolated public HTTP server.');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $socketError, $socketMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            return $process;
        }
        if (!proc_get_status($process)['running']) {
            break;
        }
        usleep(30000);
    }
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('Isolated public HTTP server did not start.');
}

function removePublicHttpFixture(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            removePublicHttpFixture($path . '/' . $name);
        }
    }
    rmdir($path);
}

function makePublicHttpFixture(string $directory, string $marker, string $outside): array
{
    ensurePublicHttp(mkdir($directory, 0700), 'Cannot create unique public fixture directory.');
    ensurePublicHttp(mkdir($directory . '/.hidden', 0700), 'Cannot create hidden fixture directory.');
    ensurePublicHttp(mkdir($directory . '/.git', 0700), 'Cannot create synthetic Git fixture directory.');
    $files = [
        '.env', '.git/config', '.secret.css', '.hidden/static.css', 'config.ini', 'config.json', 'data.db',
        'data.sql', 'backup.bak', 'style.css.old', 'secret.old.css', 'debug.log.css', 'archive.phar',
        'source.phtml', 'source.pht', 'source.inc', 'script.php.css', 'source.php', 'index.php',
        'script.js~', 'source.scss', 'source.map', 'private.txt', 'source.phps.css', 'certificate.key.css',
    ];
    foreach ($files as $file) {
        $content = str_ends_with($file, '.php') ? '<?php echo ' . var_export($marker, true) . ';' : $marker;
        ensurePublicHttp(file_put_contents($directory . '/' . $file, $content) !== false, 'Cannot create synthetic sensitive fixture.');
    }
    ensurePublicHttp(file_put_contents($directory . '/static.css', 'body { color: black; }') !== false, 'Cannot create CSS fixture.');
    ensurePublicHttp(file_put_contents($directory . '/static.js', 'window.irisFixture = true;') !== false, 'Cannot create JavaScript fixture.');
    ensurePublicHttp(symlink($outside . '/outside.css', $directory . '/outside.css'), 'Cannot create external file symlink fixture.');
    ensurePublicHttp(symlink($outside, $directory . '/linked'), 'Cannot create external directory symlink fixture.');
    ensurePublicHttp(symlink($directory . '/static.css', $directory . '/inside.css'), 'Cannot create internal symlink fixture.');
    return array_merge($files, ['outside.css', 'linked/outside.css', 'inside.css']);
}

function assertPublicHttpDenied(array $response, string $marker, string $path, array $statuses = [404]): void
{
    ensurePublicHttp(in_array($response['status'], $statuses, true), 'Sensitive public request was not rejected (HTTP ' . $response['status'] . ').');
    ensurePublicHttp(!str_contains($response['body'], $marker), 'Sensitive fixture contents were disclosed.');
    ensurePublicHttp(!str_contains($response['body'], $path), 'Rejected request path was disclosed.');
    ensurePublicHttp(!str_contains($response['body'], dirname(__DIR__)), 'Local repository path was disclosed.');
}

$public = dirname(__DIR__) . '/public';
$identifier = 'iris-public-fixture-' . bin2hex(random_bytes(8));
$directory = sys_get_temp_dir() . '/' . $identifier;
$fixture = $public . '/' . $identifier;
$marker = 'Synthetic protected fixture ' . bin2hex(random_bytes(12));
$phpProcess = null;
$apacheProcess = null;
$passed = 0;
$failures = [];
$apacheStatus = 'Apache checks skipped: httpd or required modules are unavailable.';

try {
    ensurePublicHttp(mkdir($directory, 0700), 'Cannot create isolated public test directory.');
    ensurePublicHttp(mkdir($directory . '/sessions', 0700), 'Cannot create isolated public sessions.');
    ensurePublicHttp(mkdir($directory . '/outside', 0700), 'Cannot create external fixture directory.');
    ensurePublicHttp(file_put_contents($directory . '/outside/outside.css', $marker) !== false, 'Cannot create external synthetic file.');
    $sensitive = makePublicHttpFixture($fixture, $marker, $directory . '/outside');
    $address = publicHttpAddress();
    $base = 'http://' . $address;
    $phpProcess = startPublicHttpProcess([
        PHP_BINARY, '-d', 'session.save_path=' . $directory . '/sessions', '-d', 'display_errors=0',
        '-S', $address, '-t', $public, $public . '/router.php',
    ], $address, $directory . '/php-server.log');
    $tests = [];
    $tests['actual homepage'] = static function () use ($base): void {
        $response = publicHttpRequest($base, '/');
        ensurePublicHttp($response['status'] === 200 && str_contains($response['body'], '<html'));
    };
    $tests['direct front controller'] = static function () use ($base): void {
        ensurePublicHttp(publicHttpRequest($base, '/index.php')['status'] === 200);
    };
    $tests['actual nested application page'] = static function () use ($base): void {
        $response = publicHttpRequest($base, '/home/index');
        ensurePublicHttp($response['status'] === 200 && str_contains($response['body'], '<html'));
        ensurePublicHttp(str_contains($response['body'], 'href="/assets/css/nucleo-icons.css"'), 'Nested page does not use root-relative assets.');
    };
    foreach (['/assets/css/nucleo-icons.css', '/assets/js/core/bootstrap.min.js', '/assets/fonts/nucleo.woff2', '/assets/img/favicon.png'] as $asset) {
        $tests['existing asset ' . basename($asset)] = static function () use ($base, $asset): void {
            $response = publicHttpRequest($base, $asset);
            ensurePublicHttp($response['status'] === 200 && $response['body'] !== '');
        };
    }
    foreach (['static.css', 'static.js'] as $asset) {
        $tests['synthetic asset ' . $asset] = static function () use ($base, $identifier, $asset): void {
            ensurePublicHttp(publicHttpRequest($base, '/' . $identifier . '/' . $asset)['status'] === 200);
        };
    }
    $tests['encoded ordinary asset characters'] = static function () use ($base, $identifier): void {
        ensurePublicHttp(publicHttpRequest($base, '/' . $identifier . '/%73tatic.css')['status'] === 200);
    };
    $tests['HEAD static asset'] = static function () use ($base): void {
        $response = publicHttpRequest($base, '/assets/css/nucleo-icons.css', 'HEAD');
        ensurePublicHttp($response['status'] === 200 && $response['body'] === '');
    };
    foreach ($sensitive as $position => $file) {
        $tests['sensitive or symlink fixture ' . $position] = static function () use ($base, $identifier, $file, $marker): void {
            $path = '/' . $identifier . '/' . $file;
            assertPublicHttpDenied(publicHttpRequest($base, $path), $marker, $path);
        };
    }
    $forbidden = [
        '/router.php', '/router.php/extra', '/index.php/extra', '/.env', '/.git/config',
        '/App/Config.php', '/' . $identifier . '/%2eenv', '/' . $identifier . '/%2ehidden/static.css',
        '/' . $identifier . '/../' . $identifier . '/static.css',
        '/' . $identifier . '/%2e%2e/' . $identifier . '/static.css',
        '/' . $identifier . '/%252e%252e/' . $identifier . '/static.css',
        '/' . $identifier . '/%2fstatic.css', '/' . $identifier . '/%5cstatic.css',
        '/' . $identifier . '/static.css%00', '/' . $identifier . '/%invalid.css',
    ];
    foreach ($forbidden as $position => $path) {
        $tests['forbidden raw or encoded path ' . $position] = static function () use ($base, $path, $marker): void {
            assertPublicHttpDenied(publicHttpRequest($base, $path), $marker, $path);
        };
    }
    $tests['static POST is rejected'] = static function () use ($base, $identifier, $marker): void {
        $path = '/' . $identifier . '/static.css';
        assertPublicHttpDenied(publicHttpRequest($base, $path, 'POST'), $marker, $path);
    };
    $tests['HEAD sensitive file'] = static function () use ($base, $identifier, $marker): void {
        $path = '/' . $identifier . '/.env';
        $response = publicHttpRequest($base, $path, 'HEAD');
        assertPublicHttpDenied($response, $marker, $path);
        ensurePublicHttp($response['body'] === '');
    };
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . $exception->getMessage();
        }
    }

    $apacheBinary = PHP_OS_FAMILY === 'Darwin' ? '/usr/sbin/httpd' : '/usr/sbin/apache2';
    $modules = PHP_OS_FAMILY === 'Darwin' ? '/usr/libexec/apache2' : '/usr/lib/apache2/modules';
    $apacheModules = ['mpm_event', 'authz_core', 'dir', 'mime'];
    $availableModules = array_filter($apacheModules, static fn(string $module): bool => is_file($modules . '/mod_' . $module . '.so'));
    if (is_executable($apacheBinary) && count($availableModules) === count($apacheModules)
        && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        $apacheRoot = $directory . '/apache-public';
        ensurePublicHttp(mkdir($apacheRoot, 0700), 'Cannot create isolated Apache document root.');
        ensurePublicHttp(copy($public . '/.htaccess', $apacheRoot . '/.htaccess'), 'Cannot copy Apache directory rules.');
        ensurePublicHttp(file_put_contents($apacheRoot . '/index.php', 'Synthetic front controller') !== false, 'Cannot create Apache front-controller stand-in.');
        $apacheSensitive = makePublicHttpFixture($apacheRoot . '/' . $identifier, $marker, $directory . '/outside');
        $apacheAddress = publicHttpAddress();
        $apacheBase = 'http://' . $apacheAddress;
        $configuration = 'ServerRoot "' . $directory . '"' . "\n";
        foreach (['unixd', 'mpm_event', 'authz_core', 'dir', 'mime'] as $module) {
            if (is_file($modules . '/mod_' . $module . '.so')) {
                $configuration .= 'LoadModule ' . $module . '_module "' . $modules . '/mod_' . $module . '.so"' . "\n";
            }
        }
        $configuration .= 'Listen ' . $apacheAddress . "\nServerName 127.0.0.1\n";
        $configuration .= 'User #' . posix_geteuid() . "\nGroup #" . posix_getegid() . "\n";
        $configuration .= 'PidFile "' . $directory . '/apache.pid"' . "\n";
        $configuration .= 'ErrorLog "' . $directory . '/apache-error.log"' . "\n";
        $configuration .= 'TypesConfig "' . (PHP_OS_FAMILY === 'Darwin' ? '/etc/apache2/mime.types' : '/etc/mime.types') . '"' . "\n";
        $configuration .= 'DocumentRoot "' . $apacheRoot . '"' . "\n";
        $configuration .= '<Directory "' . $apacheRoot . '">' . "\nAllowOverride All\nRequire all granted\n</Directory>\n";
        $configurationFile = $directory . '/apache.conf';
        ensurePublicHttp(file_put_contents($configurationFile, $configuration) !== false, 'Cannot write isolated Apache configuration.');
        $syntaxProcess = proc_open([$apacheBinary, '-t', '-f', $configurationFile], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/apache-config.log', 'a'], 2 => ['file', $directory . '/apache-config.log', 'a']], $syntaxPipes);
        ensurePublicHttp(is_resource($syntaxProcess), 'Cannot validate isolated Apache configuration.');
        fclose($syntaxPipes[0]);
        ensurePublicHttp(proc_close($syntaxProcess) === 0, 'Isolated Apache configuration failed validation.');
        $passed++;
        $apacheProcess = startPublicHttpProcess([$apacheBinary, '-X', '-f', $configurationFile], $apacheAddress, $directory . '/apache-process.log');
        $apacheTests = [];
        $apacheTests['Apache static file'] = static function () use ($apacheBase, $identifier): void {
            ensurePublicHttp(publicHttpRequest($apacheBase, '/' . $identifier . '/static.css')['status'] === 200);
        };
        $apacheTests['Apache front-controller fallback'] = static function () use ($apacheBase): void {
            $response = publicHttpRequest($apacheBase, '/synthetic/application');
            ensurePublicHttp($response['status'] === 200 && $response['body'] === 'Synthetic front controller');
        };
        $apacheTests['Apache nested directory cannot execute its index'] = static function () use ($apacheBase, $identifier, $marker): void {
            $path = '/' . $identifier . '/';
            assertPublicHttpDenied(publicHttpRequest($apacheBase, $path), $marker, $path, [403, 404]);
        };
        foreach (array_merge($apacheSensitive, ['../../router.php', '%2ehidden/static.css']) as $position => $file) {
            $apacheTests['Apache sensitive or symlink fixture ' . $position] = static function () use ($apacheBase, $identifier, $file, $marker): void {
                $path = '/' . $identifier . '/' . $file;
                assertPublicHttpDenied(publicHttpRequest($apacheBase, $path), $marker, $path, [400, 403, 404]);
            };
        }
        foreach ($apacheTests as $name => $test) {
            try {
                $test();
                $passed++;
            } catch (Throwable $exception) {
                $failures[] = $name . ': ' . $exception->getMessage();
            }
        }
        $apacheStatus = 'Apache 2.4 configuration and synthetic HTTP directory rules checked; PHP execution uses the separate actual-bootstrap server.';
    }
} catch (Throwable $exception) {
    $failures[] = 'Fixture setup: ' . $exception->getMessage();
} finally {
    foreach ([$apacheProcess, $phpProcess] as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    if (is_dir($fixture)) {
        removePublicHttpFixture($fixture);
    }
    if (is_dir($directory)) {
        removePublicHttpFixture($directory);
    }
}

echo $passed . " public HTTP regression tests passed.\n" . $apacheStatus . "\n";
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
exit($failures === [] ? 0 : 1);
