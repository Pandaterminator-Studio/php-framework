<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Application;
use Core\Config;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\Authenticate;
use Core\Middleware\RequestId;
use Core\Testing\TestClient;
use Core\Testing\TestResponse;

function clientEnsure(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Test client expectation failed.');
    }
}

function clientExpectException(string $type, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if (!$exception instanceof $type) {
            throw $exception;
        }
        return;
    }
    throw new RuntimeException("Expected {$type}.");
}

function createTestClient(bool $structured = false): TestClient
{
    $application = new Application(__DIR__, new Config(['http' => ['structured_json' => $structured]]));
    $application->middleware(new RequestId());
    $application->router()->get('/lookup/{id}', static fn(int $id, Request $request): Response => Response::json([
        'id' => $id,
        'query' => $request->query(),
    ]));
    $application->router()->get('/context', static fn(Request $request): Response => Response::json([
        'cookies' => $request->cookies(),
        'address' => $request->server('REMOTE_ADDR'),
        'tag' => $request->header('x-fixture-tag'),
    ]));
    $authentication = new Authenticate(static fn(Request $request) =>
        $request->header('authorization') === 'Bearer fixture' ? 'fixture-user' : null);
    $application->router()->map(['POST', 'PATCH'], '/items', static fn(Request $request): Response => Response::json([
        'user' => $request->attribute('user'),
        'fields' => $request->body(),
        'raw' => $request->rawBody(),
    ], 201))->middleware($authentication)->stateless()->policy(['editable' => ['label', 'lines'], 'protected' => ['owner_id']]);
    return new TestClient($application);
}

$credentials = ['Authorization' => 'Bearer fixture'];
$tests = [];
$tests['GET follows routing, middleware and encoded query parsing'] = static function (): void {
    createTestClient()->get('/lookup/42?name=Jane+Doe&symbol=%26')
        ->assertStatus(200)->assertHeader('X-Request-Id')->assertHeader('X-Frame-Options', 'DENY')
        ->assertJson(['query' => ['symbol' => '&', 'name' => 'Jane Doe'], 'id' => 42]);
};
$tests['request metadata is isolated across calls and globals are preserved'] = static function (): void {
    $globals = [$_SERVER, $_GET, $_POST, $_COOKIE];
    $client = createTestClient();
    $first = $client->request('GET', '/context', ['X-Fixture-Tag' => 'one'], cookies: ['demo' => 'one'], server: ['REMOTE_ADDR' => '192.0.2.1']);
    $first->assertJson(['cookies' => ['demo' => 'one'], 'address' => '192.0.2.1', 'tag' => 'one']);
    $second = $client->get('/context');
    $second->assertJson(['cookies' => [], 'address' => '127.0.0.1', 'tag' => null]);
    clientEnsure($first->response()->header('x-request-id') !== $second->response()->header('x-request-id'));
    clientEnsure($globals === [$_SERVER, $_GET, $_POST, $_COOKIE]);
};
$tests['JSON requests run authentication and preserve raw and parsed input'] = static function () use ($credentials): void {
    $client = createTestClient();
    $client->json('PATCH', '/items', ['label' => 'updated'], $credentials)
        ->assertStatus(201)->assertJson(['user' => 'fixture-user', 'fields' => ['label' => 'updated'], 'raw' => '{"label":"updated"}']);
    $client->json('PATCH', '/items', ['label' => 'again'])->assertStatus(401);
    $client->json('POST', '/items', ['owner_id' => 1], $credentials)->assertStatus(403);
};
$tests['empty JSON requests use an object at the root'] = static function () use ($credentials): void {
    $data = createTestClient()->json('POST', '/items', [], $credentials)->assertStatus(201)->json();
    clientEnsure($data['raw'] === '{}');
};
$tests['structured input follows application configuration'] = static function () use ($credentials): void {
    $input = ['lines' => [['quantity' => 2]]];
    createTestClient()->json('POST', '/items', $input, $credentials)->assertStatus(400);
    $result = createTestClient(true)->json('POST', '/items', $input, $credentials)->assertStatus(201)->json();
    clientEnsure($result['fields'] === $input);
};
$tests['headers supplied with an alternate case override JSON defaults'] = static function () use ($credentials): void {
    createTestClient()->json('POST', '/items', [], $credentials + ['cOnTeNt-TyPe' => 'text/plain'])
        ->assertStatus(415)->assertHeader('content-type', 'application/json; charset=UTF-8');
    createTestClient()->json('POST', '/items', [], $credentials + ['Content-Type' => 'application/json', 'CONTENT-TYPE' => 'application/json'])
        ->assertStatus(400);
};
$tests['invalid wire requests stop before application middleware'] = static function () use ($credentials): void {
    $client = createTestClient();
    $invalid = $client->request('POST', '/items', $credentials + ['Content-Type' => 'application/json'], '{"label":');
    $invalid->assertStatus(400)->assertHeader('Content-Type', 'application/json; charset=UTF-8');
    clientEnsure($invalid->response()->header('x-request-id') === null && isset($invalid->json()['message']));
    $client->get('/lookup/42?name=one&name=two')->assertStatus(400);
};
$tests['JSON assertions distinguish lists, objects, scalar types and missing fields'] = static function (): void {
    $result = new TestResponse(Response::json(['object' => (object) [], 'list' => [0, false], 'nullable' => null]));
    $result->assertJson(['nullable' => null, 'list' => [0, false], 'object' => (object) []]);
    foreach ([
        ['object' => [], 'list' => [0, false], 'nullable' => null],
        ['object' => (object) [], 'list' => [false, 0], 'nullable' => null],
        ['object' => (object) [], 'list' => ['0', false], 'nullable' => null],
        ['object' => (object) [], 'list' => [0, false]],
    ] as $different) {
        clientExpectException(AssertionError::class, static fn() => $result->assertJson($different));
    }
    (new TestResponse(Response::json(false)))->assertJson(false);
};
$tests['JSON numeric expectations preserve representable integers and reject rounded oversized literals'] = static function (): void {
    (new TestResponse(Response::json(['value' => 1.0])))->assertJson(['value' => 1.0]);
    (new TestResponse(new Response('{"value":2e0}')))->assertJson(['value' => 2]);
    (new TestResponse(new Response('{"value":' . PHP_INT_MAX . '}')))->assertJson(['value' => PHP_INT_MAX]);
    foreach (['123456789012345678901234567890', '-123456789012345678901234567890'] as $oversized) {
        $result = new TestResponse(new Response('{"values":[ ' . $oversized . ' ]}'));
        clientEnsure($result->json()['values'][0] === $oversized);
        clientExpectException(AssertionError::class, static fn() => $result->assertJson(['values' => [(float) $oversized]]));
        clientExpectException(AssertionError::class, static fn() => $result->assertJson(['values' => [$oversized]]));
        (new TestResponse(Response::json($oversized)))->assertJson($oversized);
    }
};
$tests['assertions report invalid responses independently of PHP assert settings'] = static function (): void {
    $result = new TestResponse(Response::text('example', 202, ['X-Example' => ['one', 'two']]));
    $result->assertStatus(202)->assertHeader('x-example', ['one', 'two']);
    clientExpectException(AssertionError::class, static fn() => $result->assertStatus(200));
    clientExpectException(AssertionError::class, static fn() => $result->assertHeader('X-Missing'));
    clientExpectException(AssertionError::class, static fn() => $result->assertHeader('X-Example', 'one'));
    clientExpectException(AssertionError::class, static fn() => $result->assertJson('example'));
    clientExpectException(JsonException::class, static fn() => $result->json());
};

foreach ($tests as $name => $test) {
    try {
        $test();
    } catch (Throwable $exception) {
        fwrite(STDERR, $name . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
echo count($tests) . ' HTTP test client regression tests passed.' . PHP_EOL;
