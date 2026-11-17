<?php

final class FixtureClient
{
    private string $base;
    private string $cookies;

    public function __construct(string $base, string $cookies)
    {
        $this->base = $base;
        $this->cookies = $cookies;
    }

    public function request(string $method, string $path, $body = null, array $headers = []): array
    {
        $handle = curl_init($this->base . $path);
        $receivedHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_COOKIEJAR => $this->cookies,
            CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$receivedHeaders): int {
                $receivedHeaders[] = strtolower(trim($header));
                return strlen($header);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($response)) {
            throw new RuntimeException('Local HTTP request failed.');
        }
        return ['status' => $status, 'body' => $response, 'headers' => $receivedHeaders];
    }

    public function issue(): array
    {
        $response = $this->request('GET', '/http-fixture/edit/42');
        ensureHttp($response['status'] === 200, 'Could not issue synthetic form.');
        $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        ensureHttp(is_array($payload) && isset($payload['_integrity_token'], $payload['_csrf_token']), 'Invalid synthetic form response.');
        return $payload;
    }

    public function post(array $body, string $path = '/http-fixture/save/42', array $headers = []): array
    {
        return $this->request('POST', $path, http_build_query($body), array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers));
    }

    public function rejected(array $response, int $expectedStatus, int $executions = 0): void
    {
        ensureHttp($response['status'] === $expectedStatus, 'Unexpected rejection status.');
        ensureHttp(!in_array('x-fixture-action: executed', $response['headers'], true), 'Rejected request executed its action.');
        $status = $this->request('GET', '/http-fixture/status');
        $payload = json_decode($status['body'], true, 32, JSON_THROW_ON_ERROR);
        ensureHttp(($payload['executions'] ?? null) === $executions, 'Rejected request changed execution count.');
    }
}

function ensureHttp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeFixtureDirectory(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . '/' . $name;
        if (is_dir($path)) {
            removeFixtureDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/iris-http-tests-' . bin2hex(random_bytes(8));
$listener = null;
$process = null;
$passed = 0;
$failures = [];

try {
    ensureHttp(mkdir($directory, 0700), 'Cannot create isolated HTTP test directory.');
    ensureHttp(mkdir($directory . '/sessions', 0700), 'Cannot create isolated HTTP sessions.');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    ensureHttp(is_resource($listener), 'Cannot reserve a local test port.');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $listener = null;
    $base = 'http://' . $address;
    $process = proc_open([
        PHP_BINARY,
        '-d', 'session.save_path=' . $directory . '/sessions',
        '-d', 'post_max_size=2M',
        '-d', 'upload_max_filesize=2M',
        '-d', 'display_errors=0',
        '-S', $address,
        '-t', __DIR__ . '/fixtures',
        __DIR__ . '/fixtures/http-router.php',
    ], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, dirname(__DIR__));
    ensureHttp(is_resource($process), 'Cannot start local PHP HTTP fixture.');
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
    ensureHttp($ready, 'Local PHP HTTP fixture did not start.');

    $tests = [];
    $tests['valid form submission'] = static function (FixtureClient $client): void {
        $response = $client->post($client->issue());
        ensureHttp($response['status'] === 200, 'Valid form was rejected.');
        $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        ensureHttp($payload === ['note' => 'Synthetic note', 'id' => 42, 'price_cents' => 1995, 'owner_id' => 7, 'role' => 'customer'], 'Server values or editable input changed.');
    };
    $tests['valid JSON submission'] = static function (FixtureClient $client): void {
        $response = $client->request('POST', '/http-fixture/save/42', json_encode($client->issue(), JSON_THROW_ON_ERROR), ['Content-Type: application/json']);
        ensureHttp($response['status'] === 200, 'Valid JSON was rejected.');
    };
    foreach (['id', 'price_cents', 'owner_id', 'role'] as $field) {
        $tests['changed ' . $field] = static function (FixtureClient $client) use ($field): void {
            $body = $client->issue();
            $body[$field] = 'altered';
            $client->rejected($client->post($body), 403);
        };
        $tests['removed ' . $field] = static function (FixtureClient $client) use ($field): void {
            $body = $client->issue();
            unset($body[$field]);
            $client->rejected($client->post($body), 403);
        };
    }
    foreach (['_integrity_token', '_csrf_token'] as $field) {
        $tests['missing ' . $field] = static function (FixtureClient $client) use ($field): void {
            $body = $client->issue();
            unset($body[$field]);
            $client->rejected($client->post($body), 403);
        };
    }
    $tests['wrong session'] = static function (FixtureClient $client) use ($base, $directory): void {
        $body = $client->issue();
        $other = new FixtureClient($base, $directory . '/other-session.cookies');
        $otherForm = $other->issue();
        $body['_csrf_token'] = $otherForm['_csrf_token'];
        $other->rejected($other->post($body), 403);
    };
    $tests['changed route record'] = static function (FixtureClient $client): void {
        $client->rejected($client->post($client->issue(), '/http-fixture/save/43'), 403);
    };
    $tests['replayed request'] = static function (FixtureClient $client): void {
        $body = $client->issue();
        ensureHttp($client->post($body)['status'] === 200, 'Initial request failed.');
        $client->rejected($client->post($body), 403, 1);
    };
    $tests['wrong HTTP method'] = static function (FixtureClient $client): void {
        $client->rejected($client->request('PUT', '/http-fixture/save/42', http_build_query($client->issue()), ['Content-Type: application/x-www-form-urlencoded']), 405);
    };
    foreach (['[]', '{invalid', 'null'] as $position => $raw) {
        $tests['invalid JSON body ' . $position] = static function (FixtureClient $client) use ($raw): void {
            $client->rejected($client->request('POST', '/http-fixture/save/42', $raw, ['Content-Type: application/json']), 400);
        };
    }
    foreach (['csrf', 'integrity'] as $kind) {
        $tests['conflicting ' . $kind . ' tokens'] = static function (FixtureClient $client) use ($kind): void {
            $client->rejected($client->post($client->issue(), '/http-fixture/save/42', ['X-' . $kind . '-Token: ' . str_repeat('0', 64)]), 400);
        };
    }
    $tests['write query parameters'] = static function (FixtureClient $client): void {
        $client->rejected($client->post($client->issue(), '/http-fixture/save/42?id=43'), 400);
    };
    $tests['unexpected field'] = static function (FixtureClient $client): void {
        $body = $client->issue();
        $body['is_admin'] = '1';
        $client->rejected($client->post($body), 400);
    };
    $tests['array protected field'] = static function (FixtureClient $client): void {
        $body = $client->issue();
        $body['id'] = ['42', '43'];
        $client->rejected($client->post($body), 400);
    };
    $tests['raw GET body'] = static function (FixtureClient $client): void {
        $client->rejected($client->request('GET', '/http-fixture/edit/42', '{"role":"admin"}', ['Content-Type: application/json']), 400);
    };
    $tests['file upload'] = static function (FixtureClient $client) use ($directory): void {
        $file = $directory . '/synthetic-upload.txt';
        file_put_contents($file, 'Synthetic upload');
        $body = $client->issue();
        $body['upload'] = new CURLFile($file, 'text/plain', 'sample.txt');
        $client->rejected($client->request('POST', '/http-fixture/save/42', $body), 400);
    };
    $tests['oversized POST body'] = static function (FixtureClient $client): void {
        $body = $client->issue();
        $body['note'] = str_repeat('a', 1048577);
        $client->rejected($client->post($body), 413);
    };
    $tests['unsupported content type'] = static function (FixtureClient $client): void {
        $client->rejected($client->request('POST', '/http-fixture/save/42', 'sample', ['Content-Type: text/plain']), 415);
    };
    $tests['missing content type'] = static function (FixtureClient $client): void {
        $client->rejected($client->request('POST', '/http-fixture/save/42', 'sample', ['Content-Type:']), 415);
    };
    $tests['HTTP method override'] = static function (FixtureClient $client): void {
        $client->rejected($client->post($client->issue(), '/http-fixture/save/42', ['X-HTTP-Method-Override: DELETE']), 400);
    };
    $tests['direct Action method route'] = static function (FixtureClient $client): void {
        $client->rejected($client->request('GET', '/http-fixture/edit-action/42'), 404);
    };
    $tests['duplicate form ID cannot hide an earlier changed value'] = static function (FixtureClient $client): void {
        $raw = 'id=43&' . http_build_query($client->issue());
        $client->rejected($client->request('POST', '/http-fixture/save/42', $raw, ['Content-Type: application/x-www-form-urlencoded']), 400);
    };
    $tests['form field aliases cannot replace protected names'] = static function (FixtureClient $client): void {
        $raw = str_replace('owner_id=', 'owner.id=', http_build_query($client->issue()));
        $client->rejected($client->request('POST', '/http-fixture/save/42', $raw, ['Content-Type: application/x-www-form-urlencoded']), 400);
    };
    foreach (['"id":43', '"\\u0069d":43', '"id":{"nested":43}'] as $position => $prefix) {
        $tests['duplicate JSON ID ' . $position . ' cannot hide an earlier value'] = static function (FixtureClient $client) use ($prefix): void {
            $raw = '{' . $prefix . ',' . substr(json_encode($client->issue(), JSON_THROW_ON_ERROR), 1);
            $client->rejected($client->request('POST', '/http-fixture/save/42', $raw, ['Content-Type: application/json']), 400);
        };
    }
    $tests['overflowing JSON number is rejected before the action'] = static function (FixtureClient $client): void {
        $body = $client->issue();
        $body['note'] = 'overflow-placeholder';
        $raw = str_replace('"overflow-placeholder"', '1e309', json_encode($body, JSON_THROW_ON_ERROR));
        $client->rejected($client->request('POST', '/http-fixture/save/42', $raw, ['Content-Type: application/json']), 400);
    };
    foreach (['id=43&id=42', 'owner.id=7', 'note=%GG', 'note%5B%5D=value'] as $position => $query) {
        $tests['ambiguous query input ' . $position . ' is rejected'] = static function (FixtureClient $client) use ($query): void {
            $client->rejected($client->request('GET', '/http-fixture/edit/42?' . $query), 400);
        };
    }

    foreach ($tests as $name => $test) {
        $client = new FixtureClient($base, $directory . '/client-' . count($failures) . '-' . $passed . '.cookies');
        try {
            $test($client);
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . get_class($exception) . ' (' . $exception->getMessage() . ')';
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'HTTP fixture setup: ' . get_class($exception) . ' (' . $exception->getMessage() . ')';
} finally {
    if (is_resource($listener)) {
        fclose($listener);
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_dir($directory)) {
        removeFixtureDirectory($directory);
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
}
fwrite(STDOUT, $passed . ' HTTP security tests passed; ' . count($failures) . ' failed.' . PHP_EOL);
exit($failures === [] ? 0 : 1);
