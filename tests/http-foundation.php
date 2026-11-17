<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Http\ErrorHandler;
use Core\Http\Request;
use Core\Http\Response;
use Core\HttpException;
use Core\Middleware\MiddlewareInterface;
use Core\Middleware\Pipeline;
use Core\Validation\ValidationException;
use Core\View\Renderer;
use Psr\Log\AbstractLogger;
use Twig\Error\LoaderError;

if (PHP_SAPI === 'cli-server') {
    $request = null;
    try {
        $request = Request::fromGlobals();
        $response = match ($request->path()) {
            '/cookies' => Response::text('Synthetic cookies')->withHeader('Set-Cookie', ['iris_one=1; Path=/', 'iris_two=2; Path=/']),
            '/empty' => new Response('Must not be emitted', 204),
            '/cached' => new Response('Must not be emitted', 304),
            '/error' => (new ErrorHandler())->response(new RuntimeException('Synthetic private value'), $request),
            default => Response::json([
                'method' => $request->method(), 'uri' => $request->uri(), 'path' => $request->path(),
                'query' => $request->query(), 'body' => $request->body(), 'raw' => $request->rawBody(),
                'headers' => $request->headers(), 'cookies' => $request->cookies(),
                'server_marker' => $request->server('HTTP_X_FIXTURE_MARKER'),
            ]),
        };
    } catch (Throwable $exception) {
        $response = (new ErrorHandler())->response($exception, $request);
    }
    $response->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
    exit;
}

final class FoundationLogger extends AbstractLogger
{
    public array $events = [];
    public bool $fail = false;

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->fail) {
            throw new RuntimeException('Synthetic logger failure');
        }
        $this->events[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

function ensureFoundation(bool $condition, string $message = 'HTTP foundation assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectFoundationFailure(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        ensureFoundation($exception instanceof $type, 'Unexpected HTTP foundation error type.');
        return;
    }
    throw new RuntimeException('Expected HTTP foundation failure was not raised.');
}

function removeFoundationFixture(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            removeFoundationFixture($path . '/' . $name);
        }
    }
    rmdir($path);
}

function foundationHttp(string $base, string $path, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    $handle = curl_init($base . $path);
    $received = [];
    try {
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                $received[] = strtolower(trim($line));
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        ensureFoundation(is_string($response), 'Local HTTP foundation request failed.');
        return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response, 'headers' => $received];
    } finally {
        curl_close($handle);
    }
}

$directory = sys_get_temp_dir() . '/iris-http-foundation-' . bin2hex(random_bytes(8));
$process = null;
$passed = 0;
$failures = [];
ob_start();

try {
    ensureFoundation(mkdir($directory, 0700) && mkdir($directory . '/views', 0700) && mkdir($directory . '/cache', 0700), 'Cannot create HTTP foundation fixtures.');
    file_put_contents($directory . '/views/page.html', 'Hello {{ name }}');
    file_put_contents($directory . '/views/layout.html', '<main>{% block body %}{% endblock %}</main>');
    file_put_contents($directory . '/views/partial.html', '{{ name }}');
    file_put_contents($directory . '/views/child.html', '{% extends "layout.html" %}{% block body %}{% include "partial.html" %}{% endblock %}');
    file_put_contents($directory . '/outside.html', 'Synthetic private template');
    file_put_contents($directory . '/views/.hidden.html', 'Synthetic private template');
    symlink($directory . '/outside.html', $directory . '/views/linked.html');
    symlink($directory, $directory . '/views/linked-directory');
    file_put_contents($directory . '/views/unsafe-include.html', '{% include "linked.html" %}');
    $tests = [];
    $tests['request accessors and null preservation'] = static function (): void {
        $request = new Request('post', '/items?query=one', ['X-Test' => 'value'], ['query' => 'one', 'null' => null],
            ['count' => 0, 'flag' => false, 'null' => null], ['session' => 'synthetic'], ['SERVER_NAME' => 'synthetic'], ['initial' => 'value'], 'raw');
        ensureFoundation($request->method() === 'POST' && $request->path() === '/items' && $request->uri() === '/items?query=one');
        ensureFoundation($request->header('x-TEST') === 'value' && $request->headers() === ['x-test' => 'value']);
        ensureFoundation($request->query('query') === 'one' && $request->query('null', 'fallback') === null);
        ensureFoundation($request->body('count') === 0 && $request->body('flag') === false && $request->body('null', 'fallback') === null);
        ensureFoundation($request->body('missing', 'fallback') === 'fallback' && $request->rawBody() === 'raw');
        ensureFoundation($request->cookies() === ['session' => 'synthetic'] && $request->server('SERVER_NAME') === 'synthetic');
        ensureFoundation($request->attributes() === ['initial' => 'value']);
    };
    $tests['immutable request attributes and body'] = static function (): void {
        $original = new Request('POST', '/', [], [], ['count' => 1]);
        $changed = $original->withAttribute('user', 7)->withBody(['count' => 2]);
        ensureFoundation($original->attribute('user') === null && $original->body('count') === 1);
        ensureFoundation($changed->attribute('user') === 7 && $changed->guard()->body() === ['count' => 2]);
    };
    $tests['invalid request method and URI'] = static function (): void {
        foreach ([["GET\r\n", '/'], ['GET', '//example.test'], ['GET', 'https://example.test'], ['GET', '/%invalid'], ['GET', '/bad\\path']] as [$method, $uri]) {
            expectFoundationFailure(static fn() => new Request($method, $uri), HttpException::class);
        }
    };
    $tests['duplicate or injected request headers'] = static function (): void {
        expectFoundationFailure(static fn() => new Request('GET', '/', ['X-Test' => "one\r\nInjected: yes"]), HttpException::class);
        expectFoundationFailure(static fn() => new Request('GET', '/', ['X-Test' => 'one', 'x-test' => 'two']), HttpException::class);
    };
    foreach ([['application/json', true], ['application/problem+json', true], ['text/html, application/json;q=0.5', true], ['application/json;q=0', false], ['text/html', false]] as $position => [$accept, $expected]) {
        $tests['JSON negotiation ' . $position] = static function () use ($accept, $expected): void {
            ensureFoundation((new Request('GET', '/', ['Accept' => $accept]))->expectsJson() === $expected);
        };
    }
    $tests['JSON content type negotiation'] = static function (): void {
        ensureFoundation((new Request('POST', '/', ['Content-Type' => 'application/json; charset=utf-8']))->expectsJson());
    };
    $tests['response factories preserve JSON values'] = static function (): void {
        $response = Response::json(['count' => 0, 'flag' => false, 'empty' => []], 201);
        ensureFoundation($response->status() === 201 && str_starts_with($response->header('Content-Type'), 'application/json'));
        ensureFoundation(json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR) === ['count' => 0, 'flag' => false, 'empty' => []]);
        ensureFoundation(str_starts_with(Response::html('<p>HTML</p>')->header('Content-Type'), 'text/html'));
        ensureFoundation(str_starts_with(Response::text('Text')->header('Content-Type'), 'text/plain'));
    };
    $tests['immutable response changes'] = static function (): void {
        $original = Response::text('Original')->withHeader('X-Test', 'first');
        $changed = $original->withBody('Changed')->withStatus(202)->withHeader('x-test', 'second');
        ensureFoundation($original->body() === 'Original' && $original->status() === 200 && $original->header('X-Test') === 'first');
        ensureFoundation($changed->body() === 'Changed' && $changed->status() === 202 && $changed->header('X-Test') === 'second');
    };
    $tests['invalid response status and headers'] = static function (): void {
        foreach ([99, 600] as $status) {
            expectFoundationFailure(static fn() => new Response('', $status), InvalidArgumentException::class);
        }
        expectFoundationFailure(static fn() => new Response('', 200, ["Bad\r\nName" => 'value']), InvalidArgumentException::class);
        expectFoundationFailure(static fn() => new Response('', 200, ['X-Test' => "bad\r\nInjected: yes"]), InvalidArgumentException::class);
        expectFoundationFailure(static fn() => new Response('', 200, ['Set-Cookie' => ['valid=one', "bad\nvalue"]]), InvalidArgumentException::class);
        expectFoundationFailure(static fn() => Response::json(['bad' => "\xFF"]), JsonException::class);
    };
    $tests['safe local and explicitly external redirects'] = static function (): void {
        ensureFoundation(Response::redirect('/login?next=home')->header('Location') === '/login?next=home');
        ensureFoundation(Response::redirect('https://example.test/allowed', allowExternal: true)->status() === 302);
        foreach (['//example.test', '/%2fexample.test', '/\\example.test', 'https://example.test', 'javascript:alert(1)', "/safe\r\nLocation: other"] as $location) {
            expectFoundationFailure(static fn() => Response::redirect($location), InvalidArgumentException::class);
        }
    };
    $tests['HEAD and no-content status suppress output'] = static function (): void {
        foreach ([[200, true], [204, false], [304, false], [103, false]] as [$status, $head]) {
            ob_start();
            (new Response('Must not be emitted', $status))->send($head);
            ensureFoundation(ob_get_clean() === '');
        }
        ob_start();
        Response::text('Visible')->send();
        ensureFoundation(ob_get_clean() === 'Visible');
    };
    $tests['middleware order and immutable request handoff'] = static function (): void {
        $events = [];
        $interface = new class($events) implements MiddlewareInterface {
            public function __construct(private array &$events)
            {
            }
            public function process(Request $request, callable $next): Response
            {
                $this->events[] = 'first-before';
                $response = $next($request->withAttribute('marker', 'available'));
                $this->events[] = 'first-after';
                return $response->withHeader('X-Middleware', 'complete');
            }
        };
        $second = static function (Request $request, callable $next) use (&$events): Response {
            $events[] = 'second-before';
            $response = $next($request);
            $events[] = 'second-after';
            return $response;
        };
        $original = new Request('GET');
        $response = (new Pipeline([$interface, $second]))->handle($original, static function (Request $request) use (&$events): Response {
            $events[] = 'destination';
            return Response::text($request->attribute('marker'));
        });
        ensureFoundation($events === ['first-before', 'second-before', 'destination', 'second-after', 'first-after']);
        ensureFoundation($response->body() === 'available' && $response->header('X-Middleware') === 'complete' && $original->attributes() === []);
    };
    $tests['middleware short circuit'] = static function (): void {
        $response = (new Pipeline([static fn(Request $request, callable $next): Response => Response::text('Stopped', 403)]))
            ->handle(new Request('GET'), static fn() => throw new LogicException('Destination must not run.'));
        ensureFoundation($response->status() === 403 && $response->body() === 'Stopped');
    };
    $tests['middleware and destination type checks'] = static function (): void {
        expectFoundationFailure(static fn() => new Pipeline(['invalid middleware']), InvalidArgumentException::class);
        expectFoundationFailure(static fn() => (new Pipeline())->handle(new Request('GET'), static fn() => 'not a response'), UnexpectedValueException::class);
        expectFoundationFailure(static fn() => (new Pipeline([static fn() => 'not a response']))->handle(new Request('GET'), static fn() => Response::text('unused')), UnexpectedValueException::class);
    };
    $tests['generic errors and private metadata-only logging'] = static function (): void {
        $logger = new FoundationLogger();
        $response = (new ErrorHandler($logger))->response(new RuntimeException('Synthetic private token'), new Request('GET', '/', ['Accept' => 'application/json']));
        ensureFoundation($response->status() === 500 && json_decode($response->body(), true) === ['message' => 'Internal server error.']);
        $logged = json_encode($logger->events, JSON_THROW_ON_ERROR);
        ensureFoundation(count($logger->events) === 1 && !str_contains($logged, 'Synthetic private token') && !str_contains($logged, dirname(__DIR__)));
        $logger->fail = true;
        ensureFoundation((new ErrorHandler($logger))->response(new RuntimeException('Private'))->status() === 500);
    };
    $tests['public HTTP errors are escaped for HTML'] = static function (): void {
        $response = (new ErrorHandler())->response(new HttpException(403, '<script>public</script>'));
        ensureFoundation($response->status() === 403 && !str_contains($response->body(), '<script>') && str_contains($response->body(), '&lt;script&gt;'));
    };
    $tests['malformed public error text cannot break JSON handling'] = static function (): void {
        $response = (new ErrorHandler())->response(new HttpException(403, "Invalid \xFF"), new Request('GET', '/', ['Accept' => 'application/json']));
        ensureFoundation($response->status() === 403 && json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR) === ['message' => 'Request failed.']);
    };
    $tests['request validation and safe 422 response'] = static function (): void {
        $request = new Request('POST', '/', ['Accept' => 'application/json'], [], ['email' => 'synthetic-private-invalid-value', 'extra' => 'discarded']);
        try {
            $request->validate(['email' => 'required|email']);
            throw new RuntimeException('Expected validation failure.');
        } catch (ValidationException $exception) {
            $response = (new ErrorHandler())->response($exception, $request);
            $payload = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
            ensureFoundation($response->status() === 422 && isset($payload['errors']['email']));
            ensureFoundation(!str_contains($response->body(), 'synthetic-private-invalid-value') && !str_contains($response->body(), 'discarded'));
        }
        ensureFoundation((new Request('POST', '/', [], [], ['name' => 'Valid', 'extra' => 'discarded']))->validate(['name' => 'required|string']) === ['name' => 'Valid']);
    };
    $tests['Twig autoescaping and normal includes'] = static function () use ($directory): void {
        $renderer = new Renderer($directory . '/views');
        ensureFoundation($renderer->render('page.html', ['name' => '<script>']) === 'Hello &lt;script&gt;');
        ensureFoundation($renderer->render('child.html', ['name' => 'Included']) === '<main>Included</main>');
        ensureFoundation($renderer->response('page.html', ['name' => 'Response'], 202)->status() === 202);
    };
    $tests['Twig traversal and hidden template guards'] = static function () use ($directory): void {
        $renderer = new Renderer($directory . '/views');
        foreach (['../outside.html', $directory . '/outside.html', '..\\outside.html', '.hidden.html', 'missing.html'] as $name) {
            expectFoundationFailure(static fn() => $renderer->render($name), LoaderError::class);
        }
    };
    $tests['Twig symlink guards also cover included templates'] = static function () use ($directory): void {
        $renderer = new Renderer($directory . '/views');
        foreach (['linked.html', 'linked-directory/outside.html', 'unsafe-include.html'] as $name) {
            expectFoundationFailure(static fn() => $renderer->render($name), LoaderError::class);
        }
    };
    $tests['Twig configured cache directory'] = static function () use ($directory): void {
        ensureFoundation((new Renderer($directory . '/views', $directory . '/cache'))->render('page.html', ['name' => 'Cached']) === 'Hello Cached');
        expectFoundationFailure(static fn() => new Renderer($directory . '/missing'), InvalidArgumentException::class);
    };
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . $exception->getMessage();
        }
    }

    $listener = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    ensureFoundation(is_resource($listener), 'Cannot reserve HTTP foundation fixture port.');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, __FILE__],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, dirname(__DIR__));
    ensureFoundation(is_resource($process), 'Cannot start HTTP foundation fixture.');
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
    ensureFoundation($ready, 'HTTP foundation fixture did not start.');
    $base = 'http://' . $address;
    $wireTests = [];
    $wireTests['globals capture actual headers query and cookies'] = static function () use ($base): void {
        $response = foundationHttp($base, '/echo?query=one', headers: ['X-Fixture-Marker: captured', 'Cookie: iris_fixture=synthetic']);
        $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        ensureFoundation($response['status'] === 200 && $payload['method'] === 'GET' && $payload['path'] === '/echo' && $payload['query'] === ['query' => 'one']);
        ensureFoundation($payload['headers']['x-fixture-marker'] === 'captured' && $payload['cookies'] === ['iris_fixture' => 'synthetic'] && $payload['server_marker'] === 'captured');
    };
    $wireTests['globals preserve parsed and raw JSON'] = static function () use ($base): void {
        $body = '{"count":0,"flag":false,"note":null}';
        $response = foundationHttp($base, '/echo', 'POST', $body, ['Content-Type: application/json']);
        $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        ensureFoundation($response['status'] === 200 && $payload['raw'] === $body && $payload['body'] === ['count' => 0, 'flag' => false, 'note' => null]);
    };
    $wireTests['multiple Set-Cookie fields survive sending'] = static function () use ($base): void {
        $response = foundationHttp($base, '/cookies');
        $cookies = array_values(array_filter($response['headers'], static fn(string $header): bool => str_starts_with($header, 'set-cookie:')));
        ensureFoundation($cookies === ['set-cookie: iris_one=1; path=/', 'set-cookie: iris_two=2; path=/']);
    };
    $wireTests['HEAD 204 and 304 suppress wire bodies'] = static function () use ($base): void {
        foreach ([['/echo', 'HEAD', 200], ['/empty', 'GET', 204], ['/cached', 'GET', 304]] as [$path, $method, $status]) {
            $response = foundationHttp($base, $path, $method);
            ensureFoundation($response['status'] === $status && $response['body'] === '');
        }
    };
    $wireTests['JSON error response does not disclose original values'] = static function () use ($base): void {
        $response = foundationHttp($base, '/error', headers: ['Accept: application/json']);
        ensureFoundation($response['status'] === 500 && json_decode($response['body'], true) === ['message' => 'Internal server error.']);
    };
    $wireTests['globals reuse duplicate JSON rejection'] = static function () use ($base): void {
        ensureFoundation(foundationHttp($base, '/echo', 'POST', '{"id":1,"id":2}', ['Content-Type: application/json'])['status'] === 400);
    };
    foreach ($wireTests as $name => $test) {
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
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_dir($directory)) {
        removeFoundationFixture($directory);
    }
}

ob_end_clean();
echo $passed . " HTTP foundation regression tests passed.\n";
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
exit($failures === [] ? 0 : 1);
