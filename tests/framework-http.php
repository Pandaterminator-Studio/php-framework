<?php

final class FrameworkHttpClient
{
    public function __construct(private string $base, private string $cookies)
    {
    }

    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $handle = curl_init($this->base . $path);
        $received = [];
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => $method === 'HEAD',
                CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
                CURLOPT_COOKIEFILE => $this->cookies, CURLOPT_COOKIEJAR => $this->cookies,
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                    $received[] = strtolower(trim($line));
                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($handle);
            ensureFrameworkHttp(is_string($response), 'Local framework HTTP request failed.');
            return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response, 'headers' => $received];
        } finally {
            curl_close($handle);
        }
    }

    public function json(string $path, array $body, bool $authenticated = true): array
    {
        return $this->request('POST', $path, json_encode((object) $body, JSON_THROW_ON_ERROR), array_merge(
            ['Content-Type: application/json'], $authenticated ? ['Authorization: Bearer iris-synthetic-test-token'] : []));
    }

    public function form(string $path = '/form'): array
    {
        $response = $this->request('GET', $path);
        ensureFrameworkHttp($response['status'] === 200, 'Could not issue framework form challenge.');
        return frameworkHttpJson($response);
    }

    public function submit(array $body, string $path = '/form/42'): array
    {
        return $this->request('POST', $path, http_build_query($body), ['Content-Type: application/x-www-form-urlencoded']);
    }

    public function rejected(array $response, int $status, int $saved = 0): void
    {
        ensureFrameworkHttp($response['status'] === $status, 'Unexpected framework rejection status.');
        ensureFrameworkHttp(!in_array('x-fixture-saved: yes', $response['headers'], true), 'Rejected framework request executed its handler.');
        ensureFrameworkHttp(frameworkHttpJson($this->request('GET', '/form-status')) === ['saved' => $saved], 'Rejected framework request changed session state.');
    }
}

function ensureFrameworkHttp(bool $condition, string $message = 'Framework HTTP assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function frameworkHttpJson(array $response): array
{
    return json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
}

function removeFrameworkHttpFixture(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            removeFrameworkHttpFixture($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/iris-framework-http-' . bin2hex(random_bytes(8));
$process = null;
$passed = 0;
$failures = [];

try {
    ensureFrameworkHttp(mkdir($directory, 0700) && mkdir($directory . '/sessions', 0700), 'Cannot create framework HTTP fixture.');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    ensureFrameworkHttp(is_resource($listener), 'Cannot reserve framework HTTP fixture port.');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $process = proc_open([
        PHP_BINARY, '-d', 'session.save_path=' . $directory . '/sessions', '-d', 'display_errors=0', '-d', 'max_execution_time=20',
        '-S', $address, '-t', __DIR__ . '/fixtures', __DIR__ . '/fixtures/framework-router.php',
    ], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, dirname(__DIR__));
    ensureFrameworkHttp(is_resource($process), 'Cannot start framework HTTP fixture.');
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
    ensureFrameworkHttp($ready, 'Framework HTTP fixture did not start.');
    $tests = [];
    $tests['JSON route through the application kernel'] = static function (FrameworkHttpClient $client): void {
        $response = $client->request('GET', '/echo?query=one');
        ensureFrameworkHttp($response['status'] === 200 && frameworkHttpJson($response) === ['method' => 'GET', 'query' => ['query' => 'one']]);
        ensureFrameworkHttp(in_array('x-fixture-kernel: active', $response['headers'], true));
        ensureFrameworkHttp(in_array('x-content-type-options: nosniff', $response['headers'], true));
    };
    $tests['typed route ID and named URL'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp(frameworkHttpJson($client->request('GET', '/typed/42')) === ['id' => 42]);
        ensureFrameworkHttp(frameworkHttpJson($client->request('GET', '/url')) === ['url' => '/typed/42?q=a%26b']);
        ensureFrameworkHttp($client->request('GET', '/typed/invalid')['status'] === 400);
        ensureFrameworkHttp($client->request('GET', '/typed/999999999999999999999999')['status'] === 400);
    };
    $tests['controller constructor and action injection'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp(frameworkHttpJson($client->request('GET', '/controller/9')) === ['id' => 9, 'dependency' => 'synthetic dependency', 'method' => 'GET']);
    };
    $tests['HEAD response has no body'] = static function (FrameworkHttpClient $client): void {
        $response = $client->request('HEAD', '/echo');
        ensureFrameworkHttp($response['status'] === 200 && $response['body'] === '');
    };
    $tests['405 advertises GET and HEAD'] = static function (FrameworkHttpClient $client): void {
        $response = $client->request('POST', '/typed/42');
        ensureFrameworkHttp($response['status'] === 405 && in_array('allow: get, head', $response['headers'], true));
    };
    $tests['404 response does not disclose paths'] = static function (FrameworkHttpClient $client): void {
        $response = $client->request('GET', '/missing/synthetic-private-path');
        ensureFrameworkHttp($response['status'] === 404 && !str_contains($response['body'], 'synthetic-private-path'));
    };
    $tests['500 response is generic JSON'] = static function (FrameworkHttpClient $client): void {
        $response = $client->request('GET', '/error');
        ensureFrameworkHttp($response['status'] === 500 && frameworkHttpJson($response) === ['message' => 'Internal server error.']);
        ensureFrameworkHttp(!str_contains($response['body'], dirname(__DIR__)));
    };
    $tests['safe redirect and external redirect refusal'] = static function (FrameworkHttpClient $client): void {
        $response = $client->request('GET', '/redirect');
        ensureFrameworkHttp($response['status'] === 302 && in_array('location: /echo', $response['headers'], true));
        $rejected = $client->request('GET', '/unsafe-redirect');
        ensureFrameworkHttp($rejected['status'] === 500 && !array_filter($rejected['headers'], static fn(string $header): bool => str_starts_with($header, 'location:')));
    };
    $tests['stateless declaration alone cannot bypass authentication'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp($client->json('/stateless-unchecked', [], false)['status'] === 401);
    };
    $tests['stateless route accepts declared editable fields after authentication'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp(frameworkHttpJson($client->json('/api/echo', ['note' => 'Accepted'])) === ['note' => 'Accepted']);
        ensureFrameworkHttp(frameworkHttpJson($client->json('/api/controller', ['note' => 'Controller accepted'])) === ['note' => 'Controller accepted']);
    };
    $tests['unauthenticated requests cannot forge framework attributes'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp($client->json('/api/echo', ['note' => 'Denied'], false)['status'] === 401);
        ensureFrameworkHttp($client->request('POST', '/api/echo', '{"note":"Denied"}', ['Content-Type: application/json', 'X-Iris-Authenticated: true'])['status'] === 401);
    };
    $tests['opt-in structured JSON preserves authenticated API payloads'] = static function (FrameworkHttpClient $client): void {
        $body = ['metadata' => ['count' => 0, 'enabled' => false, 'tags' => ['one', 'two']]];
        ensureFrameworkHttp(frameworkHttpJson($client->json('/api/nested', $body)) === $body);
        ensureFrameworkHttp($client->json('/api/nested', $body, false)['status'] === 401);
    };
    $tests['nested JSON duplicate keys are rejected'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp($client->request('POST', '/api/nested', '{"metadata":{"count":1,"count":2}}',
            ['Content-Type: application/json', 'Authorization: Bearer iris-synthetic-test-token'])['status'] === 400);
    };
    $tests['authorization receives principal and route parameters'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp($client->request('GET', '/private/7')['status'] === 401);
        ensureFrameworkHttp($client->request('GET', '/private/7', headers: ['Authorization: Bearer iris-synthetic-test-token'])['status'] === 200);
        ensureFrameworkHttp($client->request('GET', '/private/8', headers: ['Authorization: Bearer iris-synthetic-test-token'])['status'] === 403);
    };
    foreach (['role', 'owner_id'] as $field) {
        $tests['stateless protected field ' . $field] = static function (FrameworkHttpClient $client) use ($field): void {
            ensureFrameworkHttp($client->json('/api/echo', ['note' => 'Denied', $field => 'changed'])['status'] === 403);
        };
    }
    $tests['stateless unexpected fields and write query are refused'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp($client->json('/api/echo', ['is_admin' => true])['status'] === 400);
        ensureFrameworkHttp($client->json('/api/echo?role=admin', ['note' => 'Denied'])['status'] === 400);
    };
    $tests['422 validation errors omit original input'] = static function (FrameworkHttpClient $client): void {
        $response = $client->json('/api/validate', ['email' => 'synthetic-private-invalid-value']);
        ensureFrameworkHttp($response['status'] === 422 && isset(frameworkHttpJson($response)['errors']['email']));
        ensureFrameworkHttp(!str_contains($response['body'], 'synthetic-private-invalid-value'));
    };
    foreach ([
        'malformed JSON' => ['{"note":"synthetic-private-parser-value"', 'application/json'],
        'duplicate JSON' => ['{"note":"first","note":"synthetic-private-parser-value"}', 'application/json'],
        'malformed form' => ['note=%invalid-synthetic-private-parser-value', 'application/x-www-form-urlencoded'],
    ] as $name => [$body, $contentType]) {
        $tests['parser error uses JSON negotiation for ' . $name] = static function (FrameworkHttpClient $client) use ($body, $contentType): void {
            $response = $client->request('POST', '/api/echo', $body, [
                'Content-Type: ' . $contentType, 'Authorization: Bearer iris-synthetic-test-token',
            ]);
            $client->rejected($response, 400);
            ensureFrameworkHttp((bool) array_filter($response['headers'], static fn(string $header): bool => str_starts_with($header, 'content-type: application/json')));
            ensureFrameworkHttp(is_string(frameworkHttpJson($response)['message'] ?? null));
            ensureFrameworkHttp(!in_array('x-fixture-kernel: active', $response['headers'], true), 'Malformed input entered the application middleware pipeline.');
            ensureFrameworkHttp(!str_contains($response['body'], 'synthetic-private-parser-value'), 'Parser error disclosed submitted values.');
        };
    }
    $tests['unconfigured session write remains denied'] = static function (FrameworkHttpClient $client): void {
        ensureFrameworkHttp($client->request('POST', '/write-denied')['status'] === 405);
    };
    $tests['session form returns original server types'] = static function (FrameworkHttpClient $client): void {
        $response = $client->submit($client->form());
        ensureFrameworkHttp($response['status'] === 200 && frameworkHttpJson($response) === ['note' => 'Synthetic note', 'id' => 42, 'price_cents' => 1995, 'owner_id' => 7, 'role' => 'reader']);
    };
    $tests['controller named challenge helper and replay prevention'] = static function (FrameworkHttpClient $client): void {
        $body = $client->form('/controller-form/42/edit');
        $response = $client->submit($body, '/controller-form/42');
        ensureFrameworkHttp($response['status'] === 200 && frameworkHttpJson($response) === ['note' => 'Synthetic note', 'id' => 42, 'price_cents' => 1995, 'owner_id' => 7, 'role' => 'reader']);
        $client->rejected($client->submit($body, '/controller-form/42'), 403, 1);
    };
    $tests['controller action aliases cannot share named challenges'] = static function (FrameworkHttpClient $client): void {
        $body = $client->form('/controller-form/42/edit');
        $client->rejected($client->submit($body, '/controller-form-alias/42'), 403);
        ensureFrameworkHttp($client->submit($body, '/controller-form/42')['status'] === 200);
    };
    foreach (['id', 'price_cents', 'owner_id', 'role'] as $field) {
        $tests['session tamper ' . $field] = static function (FrameworkHttpClient $client) use ($field): void {
            $body = $client->form();
            $body[$field] = 'changed';
            $client->rejected($client->submit($body), 403);
        };
        $tests['session missing protected field ' . $field] = static function (FrameworkHttpClient $client) use ($field): void {
            $body = $client->form();
            unset($body[$field]);
            $client->rejected($client->submit($body), 403);
        };
    }
    foreach (['_csrf_token', '_integrity_token'] as $field) {
        $tests['session missing token ' . $field] = static function (FrameworkHttpClient $client) use ($field): void {
            $body = $client->form();
            unset($body[$field]);
            $client->rejected($client->submit($body), 403);
        };
    }
    $tests['session replay does not execute twice'] = static function (FrameworkHttpClient $client): void {
        $body = $client->form();
        ensureFrameworkHttp($client->submit($body)['status'] === 200);
        $client->rejected($client->submit($body), 403, 1);
    };
    $tests['structured JSON does not relax session scalar fields'] = static function (FrameworkHttpClient $client): void {
        $body = $client->form();
        $body['note'] = ['nested' => 'Denied'];
        $client->rejected($client->json('/form/42', $body, false), 400);
    };
    $tests['session token is bound to route and action'] = static function (FrameworkHttpClient $client): void {
        $body = $client->form();
        $client->rejected($client->submit($body, '/form/43'), 403);
        $client->rejected($client->submit($body, '/other-form/42'), 403);
    };
    $tests['session token cannot transfer between clients'] = static function (FrameworkHttpClient $client) use ($base, $directory): void {
        $body = $client->form();
        $other = new FrameworkHttpClient($base, $directory . '/other.cookies');
        $otherForm = $other->form();
        $body['_csrf_token'] = $otherForm['_csrf_token'];
        $other->rejected($other->submit($body), 403);
    };
    $tests['session unknown fields never reach the handler'] = static function (FrameworkHttpClient $client): void {
        $body = $client->form();
        $body['is_admin'] = '1';
        $client->rejected($client->submit($body), 400);
    };
    foreach ($tests as $name => $test) {
        try {
            $test(new FrameworkHttpClient($base, $directory . '/client-' . $passed . '-' . count($failures) . '.cookies'));
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . $exception->getMessage();
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'Fixture setup: ' . $exception->getMessage();
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_dir($directory)) {
        removeFrameworkHttpFixture($directory);
    }
}

echo $passed . " framework HTTP regression tests passed.\n";
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
exit($failures === [] ? 0 : 1);
