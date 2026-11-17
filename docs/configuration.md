# Configuration

`Core\Config` stores application configuration separately from the legacy `App\Config` defaults. Creating a configuration object does not load files, change the process environment or connect to services.

```php
use Core\Config;

$config = new Config(['app' => ['debug' => false], 'database' => ['host' => 'localhost']]);
$config->set('database.port', 3306);
$host = $config->get('database.host');
$timezone = $config->get('app.timezone', 'Europe/Brussels');
$config->merge(['app' => ['timezone' => 'Europe/Brussels']]);
```

`get()` uses its default only when a key is missing. An explicitly stored null remains null, and `has()` returns true for it. Keys use nonempty dot-separated segments containing letters, digits, underscores or hyphens. Numeric segments can address array entries. Invalid keys throw `InvalidArgumentException`. `set()` returns void and rejects traversal through a scalar rather than silently replacing it.

`merge()` combines associative maps recursively. Lists and scalar values are replaced, not appended or merged by numeric index. An empty incoming array explicitly clears that array. This prevents old allowlist entries from unexpectedly surviving an override.

## PHP configuration files

```php
$config = Config::fromDirectory(__DIR__ . '/../config');
$host = $config->get('database.host');
```

The directory must exist and be readable. Its top-level PHP files load in sorted filename order, each under its basename: `database.php` supplies `database.*`. Each file must return an array. Files must have names beginning with a letter or underscore, followed by letters, digits, underscores or hyphens. PHP file symlinks and unreadable or nonregular PHP entries are rejected; non-PHP files are ignored. An empty directory produces an empty configuration.

PHP configuration files execute as PHP. Load only application-controlled configuration directories, never uploaded or user-selected files. Keep credentials out of committed configuration files.

## Reading environment values

```php
$debug = Config::env('IRIS_DEBUG', false);
$port = Config::env('IRIS_DB_PORT', 3306);
```

`env()` reads the process environment without modifying it. Missing variables return the supplied default. Case-insensitive `true`, `false` and `null` become their corresponding PHP values. Canonical signed decimal integers within the platform integer range become integers. Leading-zero values and out-of-range integers remain strings; other text, including decimals and empty values, remains unchanged. Variable names must contain letters, digits and underscores, beginning with a letter or underscore.

## Explicit environment-file loading

```php
Config::loadEnv(__DIR__ . '/../.env');
```

Environment files are never loaded automatically. Call `loadEnv()` explicitly before reading environment-backed configuration if this is appropriate for your application. Existing process variables and existing `$_ENV` entries are preserved by default. Passing `true` as the second argument permits overrides.

The loader accepts UTF-8 regular files up to 1 MiB, with optional UTF-8 BOM, blank lines, full-line `#` comments and `NAME=value` assignments. Matching single or double outer quotes preserve their contents. Backslashes and dollar expressions are literal: there is no escape processing, interpolation, shell evaluation or `export` syntax. Inline comments and multiline quoted values are unsupported; an unquoted `#` is part of the value. Duplicate assignments, invalid names, malformed quoted values and null bytes are rejected before any values are applied.

Loaded values update the process environment and `$_ENV`; `env()` subsequently performs the type conversion described above. The loader does not print values and rejects symlinked environment files. Keep environment files outside the public document root, restrict their permissions and never commit real secrets. Parsing is validated before mutation, but an operating-system failure while setting an environment variable can still interrupt application startup.

## Dependency container

`Core\Container` implements PSR-11. `has()` checks explicit registrations and instantiable classes without constructing them; it does not guarantee that all constructor dependencies can be resolved. Missing entries throw PSR not-found exceptions, and construction or circular-dependency failures throw PSR container exceptions.

```php
use Core\Container;

$container = new Container();
$container->instance(Config::class, $config);
$container->singleton(ClockInterface::class, SystemClock::class);
$container->bind(ReportService::class, static fn(Container $services) => new ReportService($services->get(ClockInterface::class)));
$service = $container->get(ReportService::class);
$controller = $container->make(ExampleController::class, ['route_params' => ['id' => '42']]);
```

The example service interfaces and classes are application-defined. `bind()` creates a new service on each resolution; `singleton()` caches its result. `instance()` registers an existing object. Rebinding an ID clears its cached instance, allowing test overrides. String bindings name another class or service ID; callable factories receive the container and must return an object. Registration methods return void.

`get()` autowires concrete class constructor dependencies and resolves explicitly bound interfaces. Declared defaults are honored for scalar or unavailable optional parameters, and nullable unavailable dependencies resolve to null. Required scalar parameters, ambiguous union/intersection dependencies and reference parameters need explicit values or a factory; the container does not invent configuration values.

`make()` constructs a fresh concrete class, bypassing that class's own shared registration, while its constructor dependencies still resolve through the container. Override arguments by exact constructor parameter name; unknown or positional keys are rejected. Variadic overrides must be lists. Resolve shared services through `get()` instead.
