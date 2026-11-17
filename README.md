# Iris

Iris is a reusable PHP application framework. Its `Core\` namespace provides an application kernel, HTTP requests and responses, routing, middleware, dependency injection, validation, Twig rendering, database access and command-line tools. Applications own their routes, namespaces, templates, authentication, database schema and business rules.

The framework is under active development. The repository's original `App/` and `public/` directories remain a legacy example; they are not the framework's required application structure or a bundled business product. New projects start with an empty application skeleton.

## Example projects

Each example is a separate Composer project that loads Iris from this checkout. Run `composer install` and `composer test` inside its directory. Each guide includes a runnable walkthrough in Dutch.

| Project | What you can try |
| --- | --- |
| [Workshop reservation](examples/reservation-form/README.md) | Submit a Twig form that lets visitors choose seats while Iris verifies the workshop ID and price. |
| [Coffee shop catalog API](examples/catalog-api/README.md) | Load five sample products into SQLite and request a filtered, paginated JSON catalog. |
| [Daily sales report](examples/sales-report-worker/README.md) | Queue a report from the CLI and run a worker that calculates revenue in cents. |

## Requirements

- PHP 8.3 or later within PHP 8.x and Composer 2.
- The extensions required by Composer, including cURL and OpenSSL.
- A PDO driver when using database features: SQLite or MySQL.

Booting the kernel, serving routes and using middleware do not require a database or external API account.

## Create an application

From this source checkout:

```sh
composer install --no-plugins --no-scripts
php bin/iris new ../my-application
cd ../my-application
composer install --no-plugins --no-scripts
composer test
php vendor/bin/iris route:list
php vendor/bin/iris serve --host=127.0.0.1 --port=8000
```

The generator creates application directories and configuration; it does not install dependencies or invent business routes. The generated Composer configuration references the local framework checkout. Publication on Packagist is not assumed. See [installation](docs/installation.md) for source and package layouts.

Add a route in the generated `routes/web.php`:

```php
<?php

use Core\Http\Request;
use Core\Http\Response;
use Core\Router;

return static function (Router $router): void {
    $router->get('/ping', static fn(Request $request): Response => Response::json([
        'ok' => true,
        'method' => $request->method(),
    ]))->name('ping');
};
```

The application now serves `GET /ping`; HEAD is supported without emitting a body. The root URL remains unregistered until the application defines it.

## Application kernel

An application's root `bootstrap.php` returns its kernel:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Core\Application;

return Application::create(__DIR__)->routes(__DIR__ . '/routes/web.php');
```

Its `public/index.php` runs that kernel:

```php
<?php

$application = require dirname(__DIR__) . '/bootstrap.php';
$application->run();
```

`Application::create()` loads application-owned PHP configuration when `config/` exists, creates the service container and router, and registers lazy framework services. `config()`, `container()` and `router()` expose them. Register global middleware with `middleware(...)`. `handle(Request)` returns a response without sending it, which supports isolated tests and other adapters; `run()` parses the actual HTTP request and emits the response.

## Framework components

| Component | Responsibilities |
| --- | --- |
| HTTP | Request parsing, immutable request/response objects, JSON/HTML/text responses, redirects and safe error responses |
| Routing | HTTP methods, named routes, parameter constraints, typed arguments, groups, middleware and URL generation |
| Container and configuration | PSR-11 dependency container, constructor injection, factories, singletons, PHP configuration and explicit environment-file loading |
| Validation | Scalar rules, nested object and list schemas, field allowlists and indexed 422 errors |
| Views | Application-selected Twig directories, HTML autoescaping and confined template lookup |
| Database | Lazy SQLite/MySQL connections, bound queries, ordered pagination, transactions and migrations |
| Security | Session and CSRF helpers, protected-field request policies, expiring one-use integrity challenges and authenticated encryption |
| Authentication hooks | Application-provided principal resolvers and authorization policies through middleware |
| Toolkit | Optional cache, events, JSON logging, CORS, request IDs, sessions and rate limiting |
| Background jobs | Database-backed queues, delayed processing, retries and recovery after worker crashes |
| Console | Empty application generation, local serving, route inspection, migrations and code generation |
| Testing | In-process HTTP client for real application routes, middleware, request policies and response assertions |

The container implements PSR-11 and logging accepts PSR-3 loggers. Iris's HTTP and middleware interfaces are native framework contracts; they do not claim PSR-7 or PSR-15 compatibility.

## Routes and middleware

```php
$router->get('/records/{id:\d+}', static fn(int $id): Response => Response::json(['id' => $id]))
    ->name('records.show');

$url = $router->url('records.show', ['id' => 42], ['section' => 'summary']);
```

Use `get()`, `post()`, `put()`, `patch()`, `delete()`, `options()` or `map()`. Groups combine a prefix and middleware; named routes generate encoded URLs. Controller constructors and action parameters can receive container dependencies. Callable and invokable handlers are supported alongside `Core\Controller` subclasses.

Middleware can implement `Core\Middleware\MiddlewareInterface` or be callable. Global middleware wraps route/group middleware. Authentication and authorization are application decisions: `Authenticate` calls a supplied resolver, and `Authorize` calls a supplied policy. There is no fixed user table, role schema or authentication provider.

See [routing and request policies](docs/routing.md) and [HTTP, middleware and views](docs/http.md).

Service providers register dependencies before booting, while explicit container bindings can replace framework defaults. See [architecture and extension points](docs/architecture.md) for providers, authentication resolvers and request lifetimes.

## Safe writes and request integrity

Registering a POST route does not make it writable by default. Declare its request policy, including editable and protected fields.

Browser/session writes require a valid CSRF token and a server-issued integrity challenge. The challenge identifies a trusted server-side snapshot and is bound to the session, destination route, parameters and method. Missing, altered, expired or replayed challenges are rejected. Accepted protected values retain their original server types.

Authenticated APIs can explicitly opt into `stateless()` with declared editable/protected fields. Authentication middleware must establish the principal first. Protected fields are then rejected if submitted; the API loads ownership, roles, prices and other authoritative values itself. The stateless flag alone does not grant access.

Nested API JSON is available through the explicit `http.structured_json` configuration opt-in. Duplicate-key and size limits remain enforced, and session integrity writes retain their scalar-field checks. Validate each application's nested payload schema.

Use validated input, authorize each operation and reload current records before writing. A previously issued snapshot does not establish current ownership or permission, and it does not detect arbitrary database modifications. [SECURITY.md](SECURITY.md) describes the threat model, session requirements, stale-value checks and encryption migration.

## Configuration, validation and persistence

Application configuration is separate from the old `App\Config`. Configuration files execute trusted PHP and return arrays; environment files load only when explicitly requested. The container resolves dependencies without hardcoding an application namespace.

Validation returns declared fields that pass their rules. The database layer binds values, protects accidental unfiltered updates/deletes, supports nested transactions and coordinates migrations. These mechanisms complement application authorization and data modeling; the framework provides no mandatory business schema.

- [Configuration and dependency injection](docs/configuration.md)
- [Validation](docs/validation.md)
- [Database connections, queries, transactions and migrations](docs/database.md)
- [Testing an application with HTTP requests](docs/testing.md)

## Console and optional toolkit

```sh
php vendor/bin/iris make:controller ExampleController
php vendor/bin/iris make:middleware ExampleMiddleware
php vendor/bin/iris make:migration create_example_table
php vendor/bin/iris migrate:status
php vendor/bin/iris migrate
php vendor/bin/iris migrate:rollback
```

Application commands accept `--app=PATH`. Generators refuse to overwrite existing files. Development serving does not configure a production web server. See [console commands](docs/console.md).

Applications may select memory/file caching, synchronous event listeners, a PSR-3 JSON logger and optional middleware for CORS, request IDs, sessions and fixed-window rate limiting. See [toolkit components](docs/toolkit.md) for configuration and storage options.

To run a task in a separate process, register a job handler and enqueue its payload on the application's SQLite or MySQL connection. The CLI worker handles delayed jobs, retries and expired reservations. [Background jobs](docs/queues.md)

## Deployment and package boundaries

Serve only the consumer application's `public/` directory. Keep configuration, templates, logs, caches, sessions, migrations and Composer dependencies outside that document root. The development router and Apache rules allow normal CSS/JavaScript/image/font assets while rejecting hidden paths, sensitive backups, direct scripts and symlinks.

Apache 2.4 uses `mod_dir`, `DirectoryIndex` and `FallbackResource`; enable the needed directory overrides, such as `AllowOverride All`, or install the rules in the virtual host. Configure equivalent protection for other web servers. PHP's built-in server is for local development.

In this repository, Composer dependencies live in ignored `.iris/vendor/`. The old committed `vendor/` tree is historical and is not the runtime autoloader. Consumer applications use their own Composer installation. Production Composer autoloading exposes `Core\`; the old `App\` namespace is a development mapping. Package archives exclude the old `App/`, `public/`, tests and vendor directories. Application templates and schemas belong to each consumer.

## Legacy compatibility

The original `Router::add()/dispatch()`, `Core\Controller`, `Core\View`, injected `Core\Model` PDO access and session/security helpers remain available. Existing controllers can migrate toward returned responses and constructor injection incrementally. See [routing](docs/routing.md) for the distinction between legacy dispatch and the application kernel.

The [upgrade guide](docs/upgrading.md) covers consumer-owned views, named integrity challenges, strict inputs, credentials, sessions and encryption migration.

The Google and AI wrappers remain experimental service integrations. Their presence does not establish working credentials, live provider compatibility or verified external API access. `Core\ClaudeAI` is canonical; `Core\ClaudAI` remains an alias for older callers. `cCurl` restricts requests and redirects to HTTP(S), uses finite timeouts and preserves existing destinations when downloads fail.

Google's client library is a development dependency here, not a mandatory dependency of consuming applications. Install `google/apiclient:^2.17` separately when using `Core\Google`. The framework kernel does not require Google or AI integrations.

## Validation and development

```sh
composer test
composer lint
composer validate --no-check-publish
composer check-platform-reqs
composer audit --locked
```

`composer test` lints framework, example and test PHP files, then runs discovered suites in separate processes. Tests use isolated temporary files, SQLite databases and loopback HTTP servers. They cover kernel dispatch, request policies, integrity tampering/replay, authentication hooks, validation, templates, middleware, CLI and database behavior.

The GitHub Actions workflow runs PHP 8.3 and 8.4 and configures a disposable MySQL 8.4 service for the queue integration test. To run that test locally, set `IRIS_TEST_MYSQL_SOCKET` to a private MySQL test socket or `IRIS_TEST_MYSQL_PORT` to a dedicated loopback test server, then run `php tests/integration/mysql-queue.php`. The test account needs permission to create and remove its temporary database.
