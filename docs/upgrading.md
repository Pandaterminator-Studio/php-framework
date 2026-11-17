# Upgrading an existing Iris project

This framework update is unreleased. Review and test an upgrade in an isolated application before changing a deployed application. Database migrations and persisted ciphertext require separate preparation.

## Dependencies and package boundaries

PHP 8.3+ is required. Install locked source-checkout dependencies with `composer install --no-plugins --no-scripts`. The framework source checkout uses `.iris/vendor/`; a consuming application's Composer installation normally uses `vendor/`. Never load the old committed vendor autoloaders.

Production package autoloading exposes `Core\`. A consumer owns its `App\` or other application namespace mapping. The old root `App/` and `public/` example is excluded from package archives. Google integration now requires an explicit application dependency on `google/apiclient`.

## Legacy controllers and views

`Router::add()/dispatch()` and `*Action` methods remain available. Legacy echo-based actions can continue using `Core\View`. Configure a consumer-owned template directory in its bootstrap:

```php
Core\View::configure(__DIR__ . '/App/Views');
```

When using legacy `Core\Model`, inject the application's connection explicitly:

```php
Core\Model::setConnection($application->container()->get(Core\Database\Connection::class));
```

The new kernel supports returned `Response` objects and constructor/action dependency injection. Prefer `Core\View\Renderer` or the controller's `view()` helper for application-scoped rendering. New protected controller helpers include `request`, `input`, `validate`, `json`, `view`, `redirect` and `routeIntegrityToken`; check existing subclasses for incompatible helper declarations.

## Write policies and integrity challenges

Writes are denied until the action or route declares allowed methods, editable fields and protected fields. Session writes require CSRF and single-use challenges. Unknown policy options, invalid methods, duplicate fields and overlapping editable/protected declarations are configuration errors. Nonempty protected read fields require integrity checks automatically.

Legacy controllers dispatched through `Router::add()` retain action-based `integrityToken('save', ...)`. Modern method/named routes use destination route names:

```php
$router->post('records/{id:\d+}', [RecordController::class, 'save'])->name('records.save');
$token = $this->routeIntegrityToken('records.save', $protected, ['id' => (string) $recordId]);
```

Modern tokens cannot move between URL aliases of the same controller action. Reload forms after changing route names, methods, paths or policies. Reload and authorize current records before persisting writes; tokens do not establish current authorization.

## Input and stateless APIs

Forms and query strings use flat UTF-8 field names and reject repeated keys, brackets and PHP's dot/space aliases. JSON rejects duplicate decoded keys and non-finite values. Bodies are limited to 1 MiB and collections to 256 entries. Nested JSON requires `http.structured_json => true`; session writes still accept scalar fields only.

Use explicit stateless routes and authentication middleware for APIs instead of disabling session protection. A resolver must verify a token/signature and return a principal. Declared protected API fields cannot be supplied by the client. Validate nested editable collections before using them.

## Credentials, sessions and encryption

Remove credentials from source control and rotate any previously committed real credential. Configured connections use application-owned configuration. The legacy MySQL fallback requires environment credentials. Explicit `.env` loading preserves existing server environment values by default and does not evaluate shell expressions or interpolate variables.

`Session::Destroy()` now clears state and cookies without redirecting. `Session::regenerate()` invalidates outstanding CSRF/integrity challenges; call it after login or privilege changes. Configure verified HTTPS state at the trusted reverse-proxy/server layer so secure cookies are enabled.

Authenticated encryption uses `iris:v1:` AES-256-GCM envelopes and keys of at least 32 bytes. Legacy AES-CBC ciphertext is rejected. If an application persisted older data, migrate verified copies separately before upgrading; do not introduce an unauthenticated fallback in normal requests.

## Database and release checks

Use migrations for each application's schema. SQLite integration tests run locally; live MySQL, external providers and remote GitHub CI require separate verification. MySQL DDL can commit implicitly; the migration manager cannot roll back arbitrary schema changes transactionally. Read [database recovery](database.md) before running migrations against an existing database.

Run `composer test`, `composer check-platform-reqs` and `composer audit --locked`. Verify the application's real authentication policies, database configuration and web-server document root as well. No commits, deployments or registry publication are performed by these checks.
