<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\cCurl;

function ensureCurl(bool $condition, string $message = 'HTTP helper assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function curlPayload(string $response): array
{
    return json_decode($response, true, 32, JSON_THROW_ON_ERROR);
}

function expectCurlFailure(callable $operation, string $type, ?int $code = null): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        ensureCurl($exception instanceof $type, 'Unexpected HTTP helper failure type.');
        ensureCurl($code === null || $exception->getCode() === $code, 'Unexpected HTTP helper failure code.');
        return;
    }
    throw new RuntimeException('Expected HTTP helper failure was not raised.');
}

function removeCurlFixture(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            unlink($directory . '/' . $name);
        }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/iris-curl-tests-' . bin2hex(random_bytes(8));
$process = null;
$listener = null;
$passed = 0;
$failures = [];

try {
    ensureCurl(mkdir($directory, 0700), 'Cannot create isolated HTTP helper fixture.');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    ensureCurl(is_resource($listener), 'Cannot reserve a local fixture port.');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $listener = null;
    $base = 'http://' . $address;
    $process = proc_open([
        PHP_BINARY, '-d', 'display_errors=0', '-S', $address,
        '-t', __DIR__ . '/fixtures', __DIR__ . '/fixtures/curl-router.php',
    ], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, dirname(__DIR__));
    ensureCurl(is_resource($process), 'Cannot start local HTTP helper fixture.');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $socketError, $socketMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(30000);
    }
    ensureCurl($ready, 'Local HTTP helper fixture did not start.');
    $tests = [];
    $tests['caller options override defaults'] = static function () use ($base): void {
        $payload = curlPayload(cCurl::callAPI('GET', $base . '/echo', null, [], [CURLOPT_ENCODING => 'identity']));
        ensureCurl($payload['accept_encoding'] === 'identity');
    };
    $tests['existing query and fragment'] = static function () use ($base): void {
        $payload = curlPayload(cCurl::callAPI('GET', $base . '/echo?existing=one#section', ['added' => 'two words']));
        ensureCurl($payload['query'] === ['existing' => 'one', 'added' => 'two words']);
    };
    $tests['empty query separator'] = static function () use ($base): void {
        $payload = curlPayload(cCurl::callAPI('GET', $base . '/echo?#section', ['added' => 'value']));
        ensureCurl($payload['query'] === ['added' => 'value']);
    };
    $tests['trailing query separator'] = static function () use ($base): void {
        $payload = curlPayload(cCurl::callAPI('GET', $base . '/echo?existing=one&#section', ['added' => 'value']));
        ensureCurl($payload['query'] === ['existing' => 'one', 'added' => 'value']);
    };
    foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
        $tests['JSON scalar and empty bodies for ' . $method] = static function () use ($base, $method): void {
            foreach ([[false, 'false'], [0, '0'], [[], '[]'], ['', ''], ['0', '0']] as [$input, $expected]) {
                $payload = curlPayload(cCurl::callAPI($method, $base . '/echo', $input));
                ensureCurl($payload['method'] === $method && $payload['raw'] === $expected);
            }
        };
    }
    $tests['lowercase content type and raw text'] = static function () use ($base): void {
        $payload = curlPayload(cCurl::callAPI('POST', $base . '/echo', 'Synthetic text', ['content-type' => 'text/plain', 'X-Fixture-Marker' => 'supplied']));
        ensureCurl($payload['raw'] === 'Synthetic text' && $payload['content_type'] === 'text/plain' && $payload['marker'] === 'supplied');
    };
    $tests['HEAD is sent without a response body'] = static function () use ($base): void {
        ensureCurl(cCurl::callAPI('HEAD', $base . '/echo', ['query' => 'value']) === '');
    };
    $tests['malformed raw JSON'] = static function () use ($base): void {
        expectCurlFailure(static fn() => cCurl::callAPI('POST', $base . '/echo', '{broken'), JsonException::class);
    };
    $tests['malformed encoded JSON'] = static function () use ($base): void {
        expectCurlFailure(static fn() => cCurl::callAPI('POST', $base . '/echo', ['invalid' => "\xFF"]), JsonException::class);
        expectCurlFailure(static fn() => cCurl::callAPI('POST', $base . '/echo', NAN), JsonException::class);
    };
    $tests['header injection'] = static function () use ($base): void {
        expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/echo', null, ['X-Test' => "value\r\nInjected: yes"]), InvalidArgumentException::class);
    };
    $tests['unknown request method'] = static function () use ($base): void {
        expectCurlFailure(static fn() => cCurl::callAPI('INVALID', $base . '/echo'), InvalidArgumentException::class);
    };
    $tests['unbounded timeout refused'] = static function () use ($base): void {
        expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/echo', null, [], [CURLOPT_TIMEOUT => 0]), InvalidArgumentException::class);
        expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/echo', null, [], [CURLOPT_TIMEOUT_MS => 0]), InvalidArgumentException::class);
        expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/echo', null, [], [CURLOPT_TIMEOUT => null, CURLOPT_CONNECTTIMEOUT => null]), InvalidArgumentException::class);
    };
    $tests['explicit method cannot be overridden through options'] = static function () use ($base): void {
        foreach ([CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_POST => true, CURLOPT_NOBODY => true,
            CURLOPT_HTTPGET => true, CURLOPT_UPLOAD => true, CURLOPT_POSTFIELDS => 'injected', CURLOPT_REQUEST_TARGET => '/missing'] as $option => $value) {
            expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/echo', null, [], [$option => $value]), InvalidArgumentException::class);
        }
    };
    $tests['non HTTP protocols refused'] = static function () use ($directory): void {
        expectCurlFailure(static fn() => cCurl::callAPI('GET', 'file://' . __FILE__), InvalidArgumentException::class);
        expectCurlFailure(static fn() => cCurl::multiRequest([[CURLOPT_URL => 'file://' . __FILE__]]), InvalidArgumentException::class);
        expectCurlFailure(static fn() => cCurl::downloadFile('file://' . __FILE__, $directory . '/forbidden.txt'), InvalidArgumentException::class);
        ensureCurl(!file_exists($directory . '/forbidden.txt'));
    };
    $tests['redirect protocol cannot be broadened'] = static function () use ($base): void {
        $options = [CURLOPT_FOLLOWLOCATION => true];
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $options[constant('CURLOPT_REDIR_PROTOCOLS_STR')] = 'all';
        } else {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_ALL;
        }
        expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/file-redirect', null, [], $options), RuntimeException::class, CURLE_UNSUPPORTED_PROTOCOL);
    };
    $tests['finite redirect limit'] = static function () use ($base): void {
        expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/loop', null, [], [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2]), RuntimeException::class, CURLE_TOO_MANY_REDIRECTS);
    };
    $tests['HTTP error response remains available to API callers'] = static function () use ($base): void {
        ensureCurl(cCurl::callAPI('GET', $base . '/missing') === 'Synthetic missing document');
    };
    $tests['download follows HTTP redirect'] = static function () use ($base, $directory): void {
        ensureCurl(cCurl::downloadFile($base . '/redirect', $directory . '/download.txt') === true);
        ensureCurl(file_get_contents($directory . '/download.txt') === "Synthetic download\n");
    };
    $tests['failed download preserves destination'] = static function () use ($base, $directory): void {
        file_put_contents($directory . '/preserved.txt', 'Existing destination');
        ensureCurl(cCurl::downloadFile($base . '/missing', $directory . '/preserved.txt') === false);
        ensureCurl(file_get_contents($directory . '/preserved.txt') === 'Existing destination');
        ensureCurl(glob($directory . '/.iris-download-*') === []);
    };
    $tests['redirect failure preserves destination'] = static function () use ($base, $directory): void {
        file_put_contents($directory . '/redirect-preserved.txt', 'Existing destination');
        ensureCurl(cCurl::downloadFile($base . '/loop', $directory . '/redirect-preserved.txt') === false);
        ensureCurl(file_get_contents($directory . '/redirect-preserved.txt') === 'Existing destination');
        ensureCurl(glob($directory . '/.iris-download-*') === []);
    };
    $tests['multipart upload keeps fields and file'] = static function () use ($base, $directory): void {
        file_put_contents($directory . '/upload.txt', 'Synthetic upload');
        $payload = curlPayload(cCurl::uploadFile($base . '/echo', $directory . '/upload.txt', ['note' => 'Synthetic note'], ['X-Fixture-Marker' => 'upload']));
        ensureCurl($payload['fields'] === ['note' => 'Synthetic note']);
        ensureCurl($payload['files']['file']['content'] === 'Synthetic upload' && $payload['marker'] === 'upload');
    };
    $tests['missing upload refused'] = static function () use ($base, $directory): void {
        expectCurlFailure(static fn() => cCurl::uploadFile($base . '/echo', $directory . '/missing-upload.txt'), InvalidArgumentException::class);
    };
    $tests['parallel requests preserve keys and defaults'] = static function () use ($base): void {
        $responses = cCurl::multiRequest([
            'first' => [CURLOPT_URL => $base . '/echo?request=first'],
            'second' => [CURLOPT_URL => $base . '/echo?request=second', CURLOPT_ENCODING => 'identity'],
        ]);
        ensureCurl(array_keys($responses) === ['first', 'second']);
        ensureCurl(curlPayload($responses['first'])['query'] === ['request' => 'first']);
        ensureCurl(curlPayload($responses['second'])['accept_encoding'] === 'identity');
    };
    $tests['parallel transfer failure is explicit'] = static function () use ($base): void {
        $responses = cCurl::multiRequest([
            'success' => [CURLOPT_URL => $base . '/echo'],
            'failure' => [CURLOPT_URL => 'http://127.0.0.1:1/'],
        ]);
        ensureCurl(is_string($responses['success']) && $responses['failure'] === false);
    };
    $tests['invalid cURL options clean up and allow recovery'] = static function () use ($base): void {
        expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/echo', null, [], [PHP_INT_MAX => 'invalid']), ValueError::class);
        expectCurlFailure(static fn() => cCurl::multiRequest([
            [CURLOPT_URL => $base . '/echo'],
            [CURLOPT_URL => $base . '/echo', PHP_INT_MAX => 'invalid'],
        ]), ValueError::class);
        ensureCurl(curlPayload(cCurl::callAPI('GET', $base . '/echo'))['method'] === 'GET');
        ensureCurl(cCurl::multiRequest([]) === []);
    };
    $tests['caller timeout overrides default'] = static function () use ($base): void {
        $started = microtime(true);
        expectCurlFailure(static fn() => cCurl::callAPI('GET', $base . '/slow', null, [], [CURLOPT_TIMEOUT => 1]), RuntimeException::class, CURLE_OPERATION_TIMEDOUT);
        ensureCurl(microtime(true) - $started < 1.6, 'Caller timeout was not applied.');
    };
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . $exception->getMessage();
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'Fixture setup: ' . $exception->getMessage();
} finally {
    if (is_resource($listener)) {
        fclose($listener);
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_dir($directory)) {
        removeCurlFixture($directory);
    }
}

echo $passed . " HTTP helper regression tests passed.\n";
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
exit($failures === [] ? 0 : 1);
