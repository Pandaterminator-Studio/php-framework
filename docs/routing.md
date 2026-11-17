# Routing and request policies

`Core\Application` owns a `Core\Router`. Application route files return a callable that registers routes:

```php
<?php

use Core\Http\Request;
use Core\Http\Response;
use Core\Router;

return static function (Router $router): void {
    $router->get('/health', static fn(): Response => Response::json(['ok' => true]))->name('health');
    $router->get('/records/{id:\d+}', static fn(Request $request, int $id): Response => Response::json([
        'id' => $id,
        'method' => $request->method(),
    ]))->name('records.show');
};
```

Load it with `$application->routes($file)`. A second callback argument receives the application when needed.

## Methods, parameters and names

`get()` registers GET and HEAD. `post()`, `put()`, `patch()`, `delete()` and `options()` register their named methods. `map(['GET', 'POST'], $path, $handler)` accepts an explicit list of uppercase methods. HEAD responses do not emit the handler's body.

Patterns use `{name}` for one non-slash segment and `{name:pattern}` for an explicit constraint. Literal route characters are escaped. Routes are checked in registration order, so put specific routes before overlapping parameter routes. A matched path with the wrong method returns 405 and an `Allow` header; an unmatched path returns 404.

```php
$router->url('records.show', ['id' => 42], ['section' => 'a&b']);
```

This produces `/records/42?section=a%26b`. Parameters are encoded as individual path segments and query values are encoded separately. Required parameters must be present and satisfy the registered constraint. Route names must exist and be unique. Keep names stable when applications persist links or issue integrity challenges.

Handlers receive named route parameters, the `Request` object and injectable object dependencies. An `int`, `float` or `bool` parameter is checked before conversion; malformed values return 400 instead of being silently coerced. Use `0`, `1`, `true` or `false` for boolean route values. Union/intersection route parameter types are not supported. Avoid floats for money.

## Handlers and controllers

Handlers can be closures, invokable classes, object callables, `[ControllerClass::class, 'method']` pairs or `ControllerClass@method` strings. Non-controller handler methods are invoked as registered. For a `Core\Controller` subclass, register the action name with or without the `Action` suffix:

```php
$router->get('/records/{id:\d+}', [RecordController::class, 'show']);
```

```php
final class RecordController extends Core\Controller
{
    public function showAction(int $id): Core\Http\Response
    {
        return $this->json(['id' => $id]);
    }
}
```

The container resolves constructor and action dependencies. Public instance actions are routable; framework dispatch methods, protected methods and static actions are not. Controller helpers include `request()`, `input()`, `validate()`, `json()`, `view()` and `redirect()`.

Return a `Response`, or use legacy output with a null return value. Returning a response after emitting output is an error. New handlers should return responses. A controller's `before()` may return a response to stop processing, or `false` to skip the action. `after()` runs after a successful action.

## Groups and middleware

```php
$router->group('/api', [$authentication], static function (Core\Router $router): void {
    $router->get('/profile', $profileHandler)->middleware($authorization)->name('profile.show');
});
```

Groups combine path prefixes and middleware. They can be nested; leaving a group restores the previous context even when registration throws. Global middleware wraps group middleware, which wraps route-specific middleware. Middleware may stop processing with an early response.

Resolved requests expose the selected route as `route` and its decoded parameter map as `route.parameters`. Authentication middleware puts the resolved principal in `user` and marks `iris.authenticated`. These attributes are server-side context, not fields supplied by a client.

`Authenticate($resolver)` rejects a null or false principal with 401. The resolver must verify the application's session or API credential; it does not verify arbitrary bearer strings automatically. `Authorize($policy)` permits only an explicit true return value and otherwise returns 403. Policies receive the principal and request, so they can authorize the specific route parameters and current records.

## Browser and session writes

A POST route without a write policy remains denied. Declare all editable and protected fields explicitly:

```php
$router->post('/records/{id:\d+}', static function (Core\Http\Request $request): Core\Http\Response {
    $input = $request->attribute('iris.input');
    return Core\Http\Response::json($input);
})->name('records.save')->policy([
    'editable' => ['note'],
    'protected' => ['id'],
]);
```

The route's methods are included in its policy by default. A controller may instead declare action policies through `requestPolicies()`; an explicit route policy takes precedence. Declaring a method alone does not bypass policy checks.

After authorizing access and loading the protected values from trusted storage, issue a challenge for the named destination:

```php
$token = $router->integrityToken('records.save', ['id' => 42], ['id' => '42']);
$csrf = Core\Security::generateCSRFToken();
```

Modern controllers may use `$this->routeIntegrityToken('records.save', $values, $routeParameters)` for a named destination. Omitting the parameter map uses the current route parameters. Their `integrityToken()` helper also resolves a named destination when running under a method route; the explicit `routeIntegrityToken()` spelling avoids confusion with the legacy action-name helper. Legacy controller/action dispatch retains the original action-name semantics.

Submit the protected value unchanged with `_integrity_token`, `_csrf_token` and only declared editable values. JSON clients can send the tokens as `X-Integrity-Token` and `X-CSRF-Token`. Conflicting header/body tokens are rejected.

Challenges are session-bound, scoped to their route and method, bound to route parameters, expiring and single-use. Successful verification consumes the challenge. `iris.input` contains accepted editable values plus the original server-typed protected snapshot. Controllers use `input()` for the same purpose.

Missing or altered protected values, unknown fields, invalid tokens and replayed challenges do not reach the handler. Session writes reject write query parameters and nested form data. Protected read routes also require integrity checks when they declare protected fields.

Validate editable input and recheck current authorization and records before committing a change. A challenge authenticates an issued snapshot, not current database truth or permission. See [SECURITY.md](../SECURITY.md) for stale-record checks and the complete security model.

## Authenticated stateless APIs

Stateless routes explicitly select another protection model:

```php
$router->post('/api/submit', $handler)
    ->middleware(new Core\Middleware\Authenticate($resolvePrincipal))
    ->stateless()
    ->policy([
        'editable' => ['note'],
        'protected' => ['owner_id', 'role'],
    ]);
```

The resolver and handler belong to the application. Authentication middleware must establish the principal before the guard executes. Setting `stateless()` alone returns 401; a client cannot authenticate by sending a field or header named after a framework attribute.

Stateless policies require explicit editable/protected declarations. Submitted protected fields are rejected rather than trusted or merged. Load values such as ownership, prices and roles from the current authenticated context and storage. Unknown fields and write query parameters are rejected. Authorize the operation separately; authentication is not object-level permission.

The default HTTP parser accepts flat JSON objects. Applications needing nested API payloads may explicitly set `$application->config()->set('http.structured_json', true)`. Nested JSON remains bounded and rejects duplicate keys; authenticated stateless routes still enforce their top-level editable/protected allowlists. Validate the nested schema in application code before using it. Session integrity writes continue requiring scalar fields and do not inherit this relaxation.

Use this model for credentials that do not depend on automatically sent browser cookies. Do not use `stateless()` to bypass CSRF checks for a browser session. Define CORS and credential-handling policies appropriate to the consumer.

## Legacy routing

`Router::add()` and `dispatch()` remain for the original controller/action convention. The application kernel can also dispatch registered legacy patterns after method routes. Legacy action URLs omit the literal `Action` suffix; request guards and hooks still apply.

Legacy `dispatch()` emits output and is not the request/response application kernel. Prefer method routes and `Application::handle()/run()` for new consumers. The old example `App\` namespace is not a mandatory framework namespace.
