# Framework architecture

Iris is a reusable Composer library. `Core/` contains the framework. Generated applications own their configuration, route files, controllers, templates and database migrations. The historical root `App/` and `public/` directories are development examples, excluded from the distributable package.

## Application lifecycle

`Application::create($directory)` loads PHP configuration files from `config/`, creates a PSR-11 container and registers lazy default services. No database connection is opened during boot. `routes($file)` executes a route-registration callback. `handle($request)` runs global middleware, routing, route middleware, request policy checks and the handler, returning a response. `run()` captures the actual HTTP request, handles errors and sends the response.

Controller constructors and route/action arguments support dependency injection. Typed scalar arguments bind named URL parameters. The container makes the current request available during each middleware step and handler call. Class-based middleware resolves when its step runs, so its constructor sees the request passed on by earlier middleware. A nested synchronous dispatch restores the outer container binding when it returns or throws. An application-owned singleton lives across calls to the same kernel. Keep per-request data out of shared services and stateful handler instances.

Response-producing handlers return `Core\Http\Response`. Legacy controllers that echo output remain supported; emitting output and returning a response together is rejected. Iris uses native HTTP and middleware contracts; PSR-7 and PSR-15 compatibility is not claimed. The container implements PSR-11 and logging uses PSR-3.

## Services and providers

Explicit container bindings supplied before application construction are retained for overridable services. Applications can also register or replace services afterward. Database connections, the configured cache, view renderer and event dispatcher are lazy shared services. The default logger is a PSR `NullLogger`; bind `LoggerInterface` to `JsonLogger` or another PSR-3 logger to persist events.

Providers extend `Core\ServiceProvider`:

```php
class ExampleProvider extends \Core\ServiceProvider
{
    public function register(\Core\Container $container, \Core\Config $config): void
    {
        $container->singleton(ExampleService::class, ExampleService::class);
    }

    public function boot(\Core\Application $application): void
    {
        $application->container()->get(ExampleService::class)->initialize();
    }
}
```

List provider classes in `app.providers`. All providers register before any provider boots. Provider code and PHP configuration files are trusted application source, not user-uploaded configuration.

## Authentication and authorization

`Middleware\Authenticate` accepts an application-supplied resolver. It must actually verify the session, token or signature, returning a principal on success or `null`/`false` on failure. The middleware attaches `user` and the internal `iris.authenticated` marker. Request headers and JSON fields never become trusted request attributes automatically.

`Middleware\Authorize` accepts a policy receiving the principal and current request. It proceeds only when the policy returns `true`. The framework does not impose a users table, roles schema, login website or external identity provider.

Session routes enforce CSRF and single-use integrity challenges on writes. A route explicitly marked `stateless()` requires successful authentication middleware and an editable/protected field policy. Such a route rejects submitted protected fields; its handler reloads authoritative values and checks access to individual records. Merely sending an Authorization header does not authenticate a request.

## Additional middleware

- `StartSession` explicitly starts Iris's secure native session handling before application authentication logic.
- `RequestId` generates a correlation ID and attaches it to the request and response.
- `Cors` uses an explicit origin/method/header allowlist. Preflight requests are checked without invoking a route handler. Credentialed wildcard origins are rejected. CORS does not replace authentication.
- `RateLimit` uses the configured cache for fixed-window limits. Use a shared backend and an appropriate key resolver for your deployment; the default array backend is process-local.

Route errors are converted to responses inside the global middleware pipeline, allowing outer CORS and correlation middleware to decorate them. Errors in global middleware itself are caught by the application boundary. Error reporting never depends on a functioning logger factory.

## Structured API payloads

Flat JSON and URL-encoded fields remain the default. Set `http.structured_json` to `true` to permit nested JSON objects and lists. Duplicate decoded keys, non-finite numbers, excessive depth, oversized bodies and oversized collections are still rejected. Session integrity policies continue to reject nested submitted fields. Authenticated stateless APIs can declare editable collections and [validate their fields and items](validation.md#structured-input).

No application schema, user database, queue worker or external provider is required to use the framework kernel. Optional integrations can be added through services, providers, middleware and events.
