<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Cookie;
use Core\Google;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): void {
    if ((error_reporting() & $level) !== 0) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
});
ob_start();

function assertHelper(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectHelperFailure(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        assertHelper($exception instanceof $type, 'Unexpected exception type.');
        return;
    }
    throw new RuntimeException('Expected failure was not raised.');
}

function googleFixture(array $responses, array &$history): Google
{
    $google = new Google([
        'web' => [
            'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-secret',
            'redirect_uris' => ['http://localhost/callback'],
        ],
    ], 'synthetic-api-key');
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $property = new ReflectionProperty(Google::class, 'client');
    $client = $property->getValue($google);
    $client->setHttpClient(new Client(['handler' => $handler]));
    $google->setAccessToken(['access_token' => 'synthetic-token', 'created' => time(), 'expires_in' => 3600]);
    return $google;
}

function calendarResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], '{"id":"synthetic-event"}');
}

$tests = [];
$tests['Calendar signature loads without PHP deprecations'] = static function (): void {
    $method = new ReflectionMethod(Google::class, 'createCalendarEvent');
    assertHelper(array_map(static fn(ReflectionParameter $parameter) => $parameter->getName(), $method->getParameters()) === ['calendarId', 'summary', 'description', 'start', 'end'], 'Calendar parameter order changed.');
    assertHelper($method->getParameters()[0]->getDefaultValue() === 'primary', 'Default calendar changed.');
};
$tests['Calendar keeps five positional arguments'] = static function (): void {
    $history = [];
    $google = googleFixture([calendarResponse()], $history);
    $result = $google->createCalendarEvent('synthetic-calendar', 'Summary', 'Description', '2026-11-17T10:00:00Z', '2026-11-17T11:00:00Z');
    assertHelper($result === 'synthetic-event' && count($history) === 1, 'Calendar mock request failed.');
    assertHelper(str_contains($history[0]['request']->getUri()->getPath(), '/calendars/synthetic-calendar/events'), 'Calendar ID was not preserved.');
    $body = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
    assertHelper($body['summary'] === 'Summary' && $body['description'] === 'Description' && $body['start']['dateTime'] === '2026-11-17T10:00:00Z' && $body['end']['dateTime'] === '2026-11-17T11:00:00Z', 'Calendar event fields changed.');
};
$tests['Calendar supports named arguments with primary default'] = static function (): void {
    $history = [];
    $google = googleFixture([calendarResponse()], $history);
    assertHelper($google->createCalendarEvent(summary: 'Summary', start: '2026-11-17T10:00:00Z', end: '2026-11-17T11:00:00Z') === 'synthetic-event', 'Named Calendar arguments failed.');
    assertHelper(str_contains($history[0]['request']->getUri()->getPath(), '/calendars/primary/events'), 'Default Calendar ID was not used.');
};
$tests['Calendar rejects omitted required event values before HTTP'] = static function (): void {
    $history = [];
    $google = googleFixture([], $history);
    expectHelperFailure(static fn() => $google->createCalendarEvent(), InvalidArgumentException::class);
    assertHelper($history === [], 'Invalid Calendar event made an HTTP request.');
};
$tests['Maps uses configured developer key and encodes addresses'] = static function (): void {
    $history = [];
    $google = googleFixture([new Response(200, [], '{"status":"OK","results":[]}')], $history);
    assertHelper($google->geocode('A&B / Test') === ['status' => 'OK', 'results' => []], 'Maps response changed.');
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    assertHelper($query['address'] === 'A&B / Test' && $query['key'] === 'synthetic-api-key', 'Maps request parameters were not encoded correctly.');
    assertHelper($history[0]['options']['timeout'] === 10 && $history[0]['options']['connect_timeout'] === 5, 'Maps request timeout missing.');
};
$tests['Maps distance preserves origin destination and mode'] = static function (): void {
    $history = [];
    $google = googleFixture([new Response(200, [], '{"status":"OK","rows":[]}')], $history);
    $google->setApiKey('replacement-synthetic-key');
    $google->getDistance('Origin & One', 'Destination / Two', 'walking');
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    assertHelper($query === ['origins' => 'Origin & One', 'destinations' => 'Destination / Two', 'mode' => 'walking', 'key' => 'replacement-synthetic-key'], 'Maps distance parameters changed.');
};
$tests['Maps rejects missing key before HTTP'] = static function (): void {
    $google = new Google();
    expectHelperFailure(static fn() => $google->geocode('Synthetic address'), LogicException::class);
    expectHelperFailure(static fn() => $google->setApiKey(' '), InvalidArgumentException::class);
};
$tests['Maps rejects malformed responses'] = static function (): void {
    $history = [];
    $google = googleFixture([new Response(200, [], '{invalid'), new Response(200, [], 'null')], $history);
    expectHelperFailure(static fn() => $google->geocode('Synthetic address'), UnexpectedValueException::class);
    expectHelperFailure(static fn() => $google->geocode('Synthetic address'), UnexpectedValueException::class);
};
$tests['Maps HTTP errors do not disclose request URLs or API keys'] = static function (): void {
    $history = [];
    $google = googleFixture([new Response(403, [], 'Synthetic denial')], $history);
    try {
        $google->geocode('Synthetic address');
    } catch (RuntimeException $exception) {
        assertHelper($exception->getMessage() === 'The Google Maps request failed.' && $exception->getPrevious() === null, 'Maps HTTP failure exposed transport details.');
        return;
    }
    throw new RuntimeException('Expected Maps failure was not raised.');
};
$tests['Cookie expiry ignores forged browser metadata'] = static function (): void {
    $previous = $_COOKIE;
    try {
        $_COOKIE = ['sample' => 'synthetic', 'sample_expiry' => (string) (time() + 3600)];
        $cookie = new Cookie();
        assertHelper($cookie->getExpiryDate('sample') === null, 'Client expiry metadata was trusted.');
        $expiry = time() + 120;
        assertHelper($cookie->add('sample', 'synthetic', $expiry), 'Cookie could not be queued.');
        assertHelper($cookie->getExpiryDate('sample') === $expiry, 'Queued expiry was not retained.');
        assertHelper($cookie->modify('sample', 'updated', $expiry + 120), 'Cookie modification failed.');
        assertHelper($cookie->getExpiryDate('sample') === $expiry + 120, 'Modified expiry was not retained.');
        assertHelper($cookie->delete('sample') && $cookie->getExpiryDate('sample') === null, 'Deleted cookie retained expiry.');
    } finally {
        $_COOKIE = $previous;
    }
};
$tests['Cookie arrays cannot break helpers or be treated as strings'] = static function (): void {
    $previous = $_COOKIE;
    try {
        $_COOKIE = ['safe' => '<script>synthetic</script>', 'nested' => ['role' => 'admin']];
        $cookie = new Cookie();
        assertHelper($cookie->get('nested', 'fallback') === 'fallback' && !$cookie->exists('nested'), 'Malformed cookie was accepted.');
        assertHelper($cookie->listCookies() === ['safe' => '<script>synthetic</script>'], 'Malformed cookie was listed.');
        assertHelper(!str_contains($cookie->listCookiesAsString(), '<script>'), 'Cookie HTML was not escaped.');
    } finally {
        $_COOKIE = $previous;
    }
};
$tests['Cookie session lifetime and negative expiry validation'] = static function (): void {
    $cookie = new Cookie(defaultExpiry: 0);
    assertHelper($cookie->add('session_sample', 'synthetic') && $cookie->getExpiryDate('session_sample') === null, 'Session cookie expiry was not represented as unknown.');
    expectHelperFailure(static fn() => new Cookie(defaultExpiry: -1), InvalidArgumentException::class);
    expectHelperFailure(static fn() => $cookie->add('sample', 'synthetic', -1), InvalidArgumentException::class);
};

$passed = 0;
$failures = [];
foreach ($tests as $name => $operation) {
    try {
        $operation();
        $passed++;
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . get_class($exception) . ' (' . $exception->getMessage() . ')';
    }
}

$directory = sys_get_temp_dir() . '/iris-helper-tests-' . bin2hex(random_bytes(8));
$listener = null;
$process = null;
try {
    assertHelper(mkdir($directory, 0700), 'Cannot create helper test directory.');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    assertHelper(is_resource($listener), 'Cannot reserve helper HTTP port.');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $listener = null;
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', __DIR__ . '/fixtures', __DIR__ . '/fixtures/helpers-router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, dirname(__DIR__));
    assertHelper(is_resource($process), 'Cannot start helper HTTP fixture.');
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
    assertHelper($ready, 'Helper HTTP fixture did not start.');
    $request = static function (string $path) use ($address): array {
        $handle = curl_init('http://' . $address . $path);
        $headers = [];
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$headers): int {
            if (str_starts_with(strtolower($header), 'set-cookie:')) {
                $headers[] = strtolower(trim($header));
            }
            return strlen($header);
        }]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        assertHelper($status === 200 && is_string($body), 'Helper HTTP request failed.');
        return ['headers' => $headers, 'body' => json_decode($body, true, 32, JSON_THROW_ON_ERROR)];
    };
    $httpTests = [];
    $httpTests['HTTPS cookies default to Secure and HTTP only'] = static function () use ($request): void {
        $response = $request('/https');
        assertHelper(count($response['headers']) === 1 && str_contains($response['headers'][0], '; secure') && str_contains($response['headers'][0], '; httponly') && str_contains($response['headers'][0], '; samesite=strict'), 'Default HTTPS cookie attributes are incomplete.');
    };
    $httpTests['HTTP cookies remain usable locally'] = static function () use ($request): void {
        $response = $request('/http');
        assertHelper(!str_contains($response['headers'][0], '; secure'), 'Plain HTTP cookie unexpectedly requires HTTPS.');
    };
    $httpTests['Explicit insecure cookie option is respected'] = static function () use ($request): void {
        $response = $request('/explicit-insecure');
        assertHelper(!str_contains($response['headers'][0], '; secure'), 'Explicit secure=false was ignored.');
    };
    $httpTests['Cookie deletion uses matching path domain and security attributes'] = static function () use ($request): void {
        $response = $request('/delete');
        assertHelper(count($response['headers']) === 2, 'Cookie deletion header missing.');
        foreach ($response['headers'] as $header) {
            assertHelper(str_contains($header, '; path=/fixture') && str_contains($header, '; domain=example.test') && str_contains($header, '; secure') && str_contains($header, '; httponly') && str_contains($header, '; samesite=strict'), 'Cookie deletion attributes differ from creation.');
        }
        assertHelper(str_contains($response['headers'][1], 'max-age=0') && $response['body']['expiry'] === null, 'Cookie deletion did not expire the cookie.');
    };
    $httpTests['Session cookie omits persistent expiry attributes'] = static function () use ($request): void {
        $response = $request('/session');
        assertHelper(!str_contains($response['headers'][0], 'expires=') && !str_contains($response['headers'][0], 'max-age=') && $response['body']['expiry'] === null, 'Session cookie incorrectly persists.');
    };
    foreach ($httpTests as $name => $operation) {
        try {
            $operation();
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . get_class($exception) . ' (' . $exception->getMessage() . ')';
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'Helper HTTP setup: ' . get_class($exception) . ' (' . $exception->getMessage() . ')';
} finally {
    if (is_resource($listener)) {
        fclose($listener);
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_file($directory . '/server.log')) {
        unlink($directory . '/server.log');
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}
ob_end_clean();
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
}
fwrite(STDOUT, $passed . ' helper tests passed; ' . count($failures) . ' failed.' . PHP_EOL);
exit($failures === [] ? 0 : 1);
