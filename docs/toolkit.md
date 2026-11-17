# Cache, logging, events and request limits

These components are framework building blocks. They do not add application accounts, business models, external services or background workers.

## Cache backends

`Core\Cache\CacheInterface` defines `get`, `set`, `delete`, `has`, `clear`, `add` and `increment`. Both included backends accept finite JSON-compatible scalar/array values, not objects or resources. Values retain their types, including null and false. Missing keys return the supplied `get()` default; `has()` distinguishes a cached null from a missing entry.

```php
use Core\Cache\ArrayCache;
use Core\Cache\FileCache;

$local = new ArrayCache(1024);
$shared = new FileCache($privateStorage . '/cache', 1024);
$shared->set('catalog.summary', ['count' => 42], 60);
$value = $shared->get('catalog.summary', []);
$created = $shared->add('operation.pending', true, 30);
$count = $shared->increment('operation.attempts', 1, 60);
```

TTLs are integer seconds. Null means no expiry; negative values are rejected. Expiry is inclusive: an entry expires when its timestamp is reached. `set(..., 0)` deletes the key. `add(..., 0)` stores nothing and returns false, preserving an existing entry. `increment()` creates a missing counter from zero and applies its TTL only on creation; incrementing an existing counter never extends its expiry. A missing counter with TTL zero returns the computed amount without storing it. Only integer entries can be incremented; overflow throws without replacing the counter.

Capacity defaults to 1024 entries and must be between 1 and 10000. Each encoded value is limited to 64 KiB and a bounded nesting depth. Expired-entry pruning examines only this bounded store. Old entries are evicted when capacity is needed; this is a cache, not durable storage. Keys contain 1 to 1024 bytes and are hashed rather than used as filesystem paths.

`ArrayCache` is local to one object/process. It does not coordinate independent PHP workers and is primarily useful for isolated applications and tests. Both backend constructors accept an optional callable clock returning an integer timestamp for deterministic expiry tests.

`FileCache` keeps SHA-256-keyed entries in one JSON store, bounded to 8 MiB, inside an application-controlled private directory. It creates the directory with mode 0700, verifies private permissions, uses mode 0600 for files, and rejects symlinked storage/lock/data paths. Writes use temporary files and atomic replacement while a persistent lock coordinates all readers and writers. `add()` and `increment()` are atomic across processes using the same directory and a filesystem with reliable `flock` support. `clear()` preserves the lock inode and unrelated files.

Use the same capacity configuration for all workers sharing a directory. Corrupt or inaccessible storage raises an exception rather than being silently trusted. Cache data is not encrypted; store it outside the public document root and do not treat it as a credential vault. File locking on a local host is not a distributed cache guarantee. Another backend can implement the interface, but must preserve atomic counter and expiry semantics if used for rate limiting.

## JSON logging

`Core\Logging\JsonLogger` implements PSR-3 through `AbstractLogger` and appends one JSON record per line:

```php
use Core\Logging\JsonLogger;

$logger = new JsonLogger($privateStorage . '/logs/events.jsonl', 'warning');
$logger->warning('Request rejected for account {account_id}', ['account_id' => 42]);
```

The minimum level uses the standard PSR-3 levels. Lower-priority events are skipped; unsupported levels throw the PSR log invalid-argument exception. Log directories must be private and writable; log files use mode 0600, append locking and no stdout output. Configure retention and rotation outside this logger.

Context is copied and redacted recursively. Sensitive key names containing password, token, secret, authorization, cookie, key, credential or session markers are replaced before placeholder interpolation. Throwable values expose only exception type, basename and line number: messages, traces and arguments are omitted. Other objects are described by type without invoking their conversion methods. Context depth, item counts, text lengths and a shared traversal budget are bounded. Bearer-token text is also masked.

Redaction cannot reliably recognize secrets embedded in arbitrary prose. Log stable event messages and selected metadata, not raw requests, credentials, customer records or provider responses. Application-created `Stringable` messages are converted normally, except Throwable messages, which expose only their type. Keep log paths outside public storage and restrict access.

## Events

```php
use Core\Events\Dispatcher;

$events = new Dispatcher();
$events->on('report.created', static function ($payload, string $event): void {
    notifyLocalObserver($payload);
});
$results = $events->dispatch('report.created', ['id' => 42]);
```

The example observer is application-defined. Listeners run synchronously in registration order and receive the payload and event name. `dispatch()` returns their results in the same order. Listeners registered during dispatch start on the next dispatch. An exception stops dispatch and propagates. For asynchronous processing, a listener can publish a [background job](queues.md).

## Request rate limits

`Core\Middleware\RateLimit` implements the framework middleware contract:

```php
use Core\Middleware\RateLimit;

$limit = new RateLimit($shared, 30, 60);
```

The fixed window is aligned to Unix time. A request is counted before the next handler; attempts that later fail still count. The default identity combines trusted server `REMOTE_ADDR`, method and a case/percent-encoding/trailing-slash-normalized path. Forwarded client-IP headers are not trusted. Missing or invalid server addresses share an `unknown` bucket. Query parameters do not create separate buckets.

Allowed responses receive `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` headers. Exceeding the limit returns HTTP 429 with `Retry-After` and no-store headers without invoking the next handler. JSON clients receive a JSON error; other clients receive text. Limits must be positive and windows must be 1 to 86400 seconds. Backend errors propagate rather than bypassing the limit.

An optional fourth constructor argument receives the Request and returns a nonempty string scope. Use it for a trusted authenticated account ID or a stable route-wide limit. The resolver must not trust a freely editable request field. Place account-based limits after authentication when they depend on authenticated attributes.

```php
$limit = new RateLimit($shared, 100, 60, static fn($request): string => 'account:' . $request->attribute('account_id'));
```

Provision adequate cache capacity: clearing or evicting counters resets those limits. Fixed windows can permit bursts across a boundary. The included local/file backends are not a multi-host denial-of-service protection system; a deployment can provide a shared backend and upstream network limits separately.
