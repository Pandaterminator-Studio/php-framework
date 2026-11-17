<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Cache\ArrayCache;
use Core\Cache\FileCache;
use Core\Events\Dispatcher;
use Core\Http\Request;
use Core\Http\Response;
use Core\Logging\JsonLogger;
use Core\Middleware\RateLimit;

error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): void {
    if ((error_reporting() & $level) !== 0) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
});

function toolkitEnsure(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Toolkit assertion failed.');
    }
}

function toolkitRejects(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        toolkitEnsure($exception instanceof $type);
        return;
    }
    throw new RuntimeException('Expected toolkit rejection was not raised.');
}

$directory = sys_get_temp_dir() . '/iris-toolkit-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create isolated toolkit fixtures.');
}
$tests = [];
foreach (['array', 'file'] as $backend) {
    $factory = static function (int &$clock, int $capacity = 8) use ($backend, $directory) {
        $time = static function () use (&$clock): int {
            return $clock;
        };
        return $backend === 'array' ? new ArrayCache($capacity, $time)
            : new FileCache($directory . '/' . bin2hex(random_bytes(8)), $capacity, $time);
    };
    $tests[$backend . ' cache preserves null false types and distinct hashed keys'] = static function () use ($factory): void {
        $clock = 100;
        $cache = $factory($clock);
        foreach (['nested/key' => null, 'nested_key' => false, '../key' => ['id' => 42, 'decimal' => 1.0]] as $key => $value) {
            toolkitEnsure($cache->set($key, $value));
            toolkitEnsure($cache->has($key) && $cache->get($key, 'missing') === $value);
        }
        toolkitEnsure($cache->get('unknown', 'fallback') === 'fallback');
    };
    $tests[$backend . ' cache expires precisely and supports forever and zero TTL'] = static function () use ($factory): void {
        $clock = 100;
        $cache = $factory($clock);
        $cache->set('short', 'value', 5);
        $cache->set('forever', 'value');
        $clock = 104;
        toolkitEnsure($cache->has('short'));
        $clock = 105;
        toolkitEnsure(!$cache->has('short') && $cache->has('forever'));
        $cache->set('forever', 'replacement', 0);
        toolkitEnsure(!$cache->has('forever'));
        toolkitEnsure(!$cache->add('zero', 'value', 0) && !$cache->has('zero'));
        toolkitRejects(static fn() => $cache->set('negative', 1, -1), InvalidArgumentException::class);
    };
    $tests[$backend . ' add is conditional and counter TTL never slides'] = static function () use ($factory): void {
        $clock = 100;
        $cache = $factory($clock);
        toolkitEnsure($cache->add('unique', 'first', 5));
        toolkitEnsure(!$cache->add('unique', 'second', 100));
        toolkitEnsure($cache->get('unique') === 'first');
        toolkitEnsure($cache->increment('counter', 2, 5) === 2);
        $clock = 104;
        toolkitEnsure($cache->increment('counter', 3, 100) === 5);
        $clock = 105;
        toolkitEnsure(!$cache->has('counter') && !$cache->has('unique'));
        toolkitEnsure($cache->increment('counter', 1, 5) === 1);
        toolkitEnsure($cache->increment('unstored', 1, 0) === 1 && !$cache->has('unstored'));
    };
    $tests[$backend . ' cache bounds capacity and clears only its own state'] = static function () use ($factory): void {
        $clock = 100;
        $cache = $factory($clock, 2);
        $cache->set('first', 1);
        $cache->set('second', 2);
        $cache->set('third', 3);
        toolkitEnsure(!$cache->has('first') && $cache->get('third') === 3);
        toolkitEnsure($cache->delete('second') && $cache->delete('missing'));
        toolkitEnsure($cache->clear() && !$cache->has('third'));
    };
    $tests[$backend . ' cache refuses objects oversized values and noninteger counters'] = static function () use ($factory): void {
        $clock = 100;
        $cache = $factory($clock);
        foreach ([new stdClass(), ['nested' => new stdClass()], INF, str_repeat('x', 65537)] as $value) {
            toolkitRejects(static fn() => $cache->set('invalid', $value), InvalidArgumentException::class);
        }
        $cache->set('string', '1');
        toolkitRejects(static fn() => $cache->increment('string'), UnexpectedValueException::class);
        $cache->set('maximum', PHP_INT_MAX);
        toolkitRejects(static fn() => $cache->increment('maximum'), OverflowException::class);
        toolkitEnsure($cache->get('maximum') === PHP_INT_MAX);
        toolkitRejects(static fn() => $cache->get(''), InvalidArgumentException::class);
    };
}
$tests['file cache persists safely across instances without raw keys or PHP serialization'] = static function () use ($directory): void {
    $path = $directory . '/persistence';
    $first = new FileCache($path);
    $first->set('synthetic-private-key', ['id' => 42]);
    $second = new FileCache($path);
    toolkitEnsure($second->get('synthetic-private-key') === ['id' => 42]);
    $raw = file_get_contents($path . '/cache.json');
    toolkitEnsure(!str_contains($raw, 'synthetic-private-key'));
    toolkitEnsure(is_array(json_decode($raw, true, 32, JSON_THROW_ON_ERROR)));
    toolkitEnsure((fileperms($path) & 0077) === 0 && (fileperms($path . '/cache.json') & 0077) === 0);
    file_put_contents($path . '/keep.txt', 'unrelated fixture');
    $second->clear();
    toolkitEnsure(is_file($path . '/keep.txt') && is_file($path . '/.lock'));
};
$tests['file cache rejects symlinks and corrupt state without executing it'] = static function () use ($directory): void {
    $path = $directory . '/corrupt';
    $cache = new FileCache($path);
    $cache->set('entry', 1);
    file_put_contents($path . '/cache.json', '<?php throw new RuntimeException("never execute");');
    toolkitRejects(static fn() => $cache->get('entry'), RuntimeException::class);
    $outside = $directory . '/outside.json';
    file_put_contents($outside, '{}');
    unlink($path . '/cache.json');
    symlink($outside, $path . '/cache.json');
    toolkitRejects(static fn() => $cache->set('entry', 2), RuntimeException::class);
    toolkitEnsure(file_get_contents($outside) === '{}');
    symlink($path, $directory . '/linked-cache');
    toolkitRejects(static fn() => new FileCache($directory . '/linked-cache'), InvalidArgumentException::class);
};
$tests['file cache counters and add remain atomic across PHP processes'] = static function () use ($directory): void {
    $path = $directory . '/concurrency';
    $cache = new FileCache($path, 64);
    $workers = [];
    $script = 'require $argv[1]; $cache=new Core\\Cache\\FileCache($argv[2],64);$won=$cache->add("winner",1,60);for($index=0;$index<40;$index++){$cache->increment("counter",1,60);}echo $won?"1":"0";';
    try {
        for ($index = 0; $index < 6; $index++) {
            $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__) . '/.iris/vendor/autoload.php', $path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/worker-errors.log', 'a']], $pipes);
            toolkitEnsure(is_resource($process));
            $workers[] = ['process' => $process, 'output' => $pipes[1]];
        }
        $winners = 0;
        foreach ($workers as &$worker) {
            $output = stream_get_contents($worker['output']);
            fclose($worker['output']);
            toolkitEnsure(proc_close($worker['process']) === 0);
            $worker['process'] = null;
            $winners += $output === '1' ? 1 : 0;
        }
        unset($worker);
        toolkitEnsure($winners === 1 && $cache->get('counter') === 240);
    } finally {
        foreach ($workers as $worker) {
            if (is_resource($worker['output'])) {
                fclose($worker['output']);
            }
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
    }
};
$tests['JSON logger filters levels redacts nested keys and never writes values to stdout'] = static function () use ($directory): void {
    $path = $directory . '/logs/events.jsonl';
    $logger = new JsonLogger($path, 'warning');
    toolkitEnsure($logger instanceof Psr\Log\LoggerInterface);
    ob_start();
    try {
        $logger->info('ignored');
        $logger->warning('Event {id} {password}', ['id' => 42, 'password' => 'synthetic-password', 'nested' => ['api_key' => 'synthetic-key', 'safe' => 'visible'], 'authorization' => 'Bearer synthetic-token']);
        toolkitEnsure(ob_get_contents() === '');
    } finally {
        ob_end_clean();
    }
    $raw = file_get_contents($path);
    toolkitEnsure(!str_contains($raw, 'synthetic-password') && !str_contains($raw, 'synthetic-key') && !str_contains($raw, 'synthetic-token'));
    $record = json_decode(trim($raw), true, 32, JSON_THROW_ON_ERROR);
    toolkitEnsure($record['level'] === 'warning' && $record['context']['nested']['safe'] === 'visible');
    toolkitEnsure($record['message'] === 'Event 42 [REDACTED]');
    toolkitRejects(static fn() => $logger->log('invalid', 'message'), Psr\Log\InvalidArgumentException::class);
};
$tests['logger exceptions expose metadata without messages traces or object conversion'] = static function () use ($directory): void {
    $path = $directory . '/exception-logs/events.jsonl';
    $logger = new JsonLogger($path);
    $exception = new RuntimeException('synthetic-sensitive-exception');
    $object = new class implements Stringable {
        public function __toString(): string
        {
            throw new RuntimeException('Context objects must not be converted.');
        }
    };
    $logger->error($exception, ['exception' => $exception, 'object' => $object, 'message' => 'Bearer synthetic-inline-token']);
    $raw = file_get_contents($path);
    toolkitEnsure(!str_contains($raw, 'synthetic-sensitive-exception') && !str_contains($raw, 'synthetic-inline-token'));
    $record = json_decode(trim($raw), true, 32, JSON_THROW_ON_ERROR);
    toolkitEnsure($record['context']['exception']['type'] === RuntimeException::class);
    toolkitEnsure(!isset($record['context']['exception']['trace']) && !isset($record['context']['exception']['message']));
};
$tests['logger bounds large and recursive context without serializing objects'] = static function () use ($directory): void {
    $path = $directory . '/bounded-logs/events.jsonl';
    $logger = new JsonLogger($path);
    $recursive = [];
    $recursive['self'] = &$recursive;
    $logger->info('bounded', ['items' => array_fill(0, 200, str_repeat('x', 4096)), 'recursive' => $recursive]);
    $raw = file_get_contents($path);
    toolkitEnsure(strlen($raw) < 131072 && str_contains($raw, 'TRUNCATED'));
    toolkitEnsure(is_array(json_decode(trim($raw), true, 32, JSON_THROW_ON_ERROR)));
};
$tests['events preserve registration order defer new listeners and propagate failures'] = static function (): void {
    $dispatcher = new Dispatcher();
    $seen = [];
    $dispatcher->on('saved', static function ($payload, string $event) use (&$seen, $dispatcher): string {
        $seen[] = [$event, $payload, 'first'];
        $dispatcher->on('saved', static fn() => 'later');
        return 'first';
    });
    $dispatcher->on('saved', static function ($payload) use (&$seen): string {
        $seen[] = ['saved', $payload, 'second'];
        return 'second';
    });
    toolkitEnsure($dispatcher->dispatch('saved', 42) === ['first', 'second']);
    toolkitEnsure(count($seen) === 2 && $seen[0][2] === 'first' && $seen[1][2] === 'second');
    toolkitEnsure($dispatcher->dispatch('saved', 43) === ['first', 'second', 'later']);
    toolkitEnsure($dispatcher->dispatch('unknown') === []);
    $dispatcher->on('failed', static fn() => throw new RuntimeException('synthetic'));
    $dispatcher->on('failed', static function () use (&$seen): void {
        $seen[] = 'must not execute';
    });
    toolkitRejects(static fn() => $dispatcher->dispatch('failed'), RuntimeException::class);
    toolkitEnsure(count($seen) === 4);
};
$tests['rate limiter short circuits and retains response headers'] = static function (): void {
    $limiter = new RateLimit(new ArrayCache(), 2, 86400);
    $request = new Request('GET', '/limited', ['Accept' => 'application/json'], [], [], [], ['REMOTE_ADDR' => '192.0.2.10']);
    $calls = 0;
    $next = static function () use (&$calls): Response {
        $calls++;
        return Response::text('allowed', 201, ['X-Handler' => 'called']);
    };
    $first = $limiter->process($request, $next);
    toolkitEnsure($first->status() === 201 && $first->header('X-Handler') === 'called' && $first->header('X-RateLimit-Remaining') === '1');
    toolkitEnsure($limiter->process($request, $next)->status() === 201);
    $blocked = $limiter->process($request, $next);
    toolkitEnsure($blocked->status() === 429 && $calls === 2 && (int) $blocked->header('Retry-After') >= 1);
    toolkitEnsure($blocked->header('X-RateLimit-Remaining') === '0' && str_contains($blocked->body(), 'Too many requests.'));
};
$tests['rate limiter ignores forwarded identities and scopes method path and trusted IP'] = static function (): void {
    $limiter = new RateLimit(new ArrayCache(), 1, 86400);
    $next = static fn() => Response::text('allowed');
    $make = static fn(string $method, string $path, string $address, string $forwarded): Request => new Request($method, $path, ['X-Forwarded-For' => $forwarded], [], [], [], ['REMOTE_ADDR' => $address]);
    toolkitEnsure($limiter->process($make('GET', '/one', '192.0.2.1', '198.51.100.1'), $next)->status() === 200);
    toolkitEnsure($limiter->process($make('GET', '/one', '192.0.2.1', '198.51.100.2'), $next)->status() === 429);
    toolkitEnsure($limiter->process($make('GET', '/ONE/', '192.0.2.1', '198.51.100.2'), $next)->status() === 429);
    toolkitEnsure($limiter->process($make('GET', '/%6fne', '192.0.2.1', '198.51.100.2'), $next)->status() === 429);
    toolkitEnsure($limiter->process($make('GET', '/two', '192.0.2.1', '198.51.100.1'), $next)->status() === 200);
    toolkitEnsure($limiter->process($make('POST', '/one', '192.0.2.1', '198.51.100.1'), $next)->status() === 200);
    toolkitEnsure($limiter->process($make('GET', '/one', '192.0.2.2', '198.51.100.1'), $next)->status() === 200);
};
$tests['rate limiter supports explicit trusted scopes and rejects invalid configuration'] = static function (): void {
    $limiter = new RateLimit(new ArrayCache(), 1, 86400, static fn(Request $request): string => $request->attribute('account'));
    $next = static fn() => Response::text('allowed');
    toolkitEnsure($limiter->process((new Request('GET', '/one'))->withAttribute('account', 'synthetic'), $next)->status() === 200);
    toolkitEnsure($limiter->process((new Request('GET', '/two'))->withAttribute('account', 'synthetic'), $next)->status() === 429);
    toolkitRejects(static fn() => new RateLimit(new ArrayCache(), 0, 60), InvalidArgumentException::class);
    toolkitRejects(static fn() => new RateLimit(new ArrayCache(), 1, 0), InvalidArgumentException::class);
    $invalid = new RateLimit(new ArrayCache(), 1, 60, static fn() => null);
    toolkitRejects(static fn() => $invalid->process(new Request('GET'), $next), InvalidArgumentException::class);
};

$passed = 0;
$failures = [];
try {
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . get_class($exception);
        }
    }
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isLink() || $file->isFile() ? unlink($file->getPathname()) : rmdir($file->getPathname());
    }
    rmdir($directory);
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
}
echo $passed . '/' . count($tests) . ' toolkit tests passed.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
