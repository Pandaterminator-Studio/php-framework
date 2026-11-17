# Request integrity and security

## Running Iris

Use PHP 8.3 or later and Composer 2. Install the locked dependencies before starting:

```sh
composer install --no-plugins --no-scripts
php -S 127.0.0.1:8181 -t public public/router.php
composer test
composer lint
composer audit --locked
```

The Composer installation now lives in `.iris/vendor/`. The old committed `vendor/` tree is historical and is not loaded by the application. Do not point an application at its old autoloaders. Serve only `public/` with Apache/Nginx; never expose the repository root. Apache 2.4 uses `mod_dir` and `FallbackResource /index.php` rather than a query-string rewrite; permit the required directory overrides or install the rules in the virtual host.

The development router and Apache configuration allow direct static access only to CSS, JavaScript, images and fonts. They block hidden paths, backup/configuration files, non-front-controller scripts and symlinks. Directory indexing and symlink following are disabled in Apache. Keep these restrictions when configuring another web server; application request checks do not protect files served directly by that server.

Configure database connections through the application's database configuration. The core does not implicitly load `.env` files; generated applications explicitly call `Config::loadEnv()` from their bootstrap when that file exists. The legacy MySQL fallback uses the variables in `.env.example` and requires an explicit `IRIS_DB_PASSWORD`. Configured SQLite connections do not require a password. The previous hardcoded password has been removed from current configuration; if it was used anywhere, rotate it because Git history still contains it. Tests use isolated temporary databases, never customer databases.

## What runs on every request

Every controller action reached through `Router::dispatch()` passes through the final `Controller::executeAction()` dispatcher and `RequestGuard`. URLs ending in an action method's literal `Action` suffix cannot bypass the guard or `before()` hook. Framework methods, private/protected actions and static actions cannot be routed.

Actions permit GET and HEAD by default. A write action must explicitly declare its HTTP methods, editable fields and protected fields. Session writes require both a session CSRF token and a data integrity challenge. A missing challenge is rejected, even if all protected fields were removed from the request. Stateless routes use the separate authenticated policy described below; an unverified Authorization header cannot bypass session checks.

Read requests do not prove ownership of a URL ID. Load records using the current user's permissions on every read. Declaring nonempty `protected` fields requires a challenge on a read action automatically; omitting `integrity` or setting it to false cannot disable that protection. A read action can also opt into a challenge explicitly with `integrity => true` and a declared protected-field list. Prefer headers for these tokens so they do not enter URLs/history.

Request policy options are limited to `methods`, `editable`, `protected` and `integrity`. Methods must be a nonempty list of unique supported uppercase HTTP verbs. Field lists must contain unique valid names, and editable and protected lists cannot overlap. Invalid policy definitions fail closed instead of silently weakening checks.

## Protecting IDs, prices, ownership and roles

The browser receives a random 256-bit challenge. Iris keeps the original values in the server session, bound to the controller/action, HTTP method, route parameters and expiry. There is no client-controlled payload to re-sign or a public checksum to recompute. A successful check consumes the challenge, preventing replay in the same session. Session storage must support locking or equivalent atomic consumption; PHP's default file sessions do this.

Issue challenges only from trusted server data after authorizing the current user. Never issue them by copying `$_POST`, JSON, query parameters or an unverified token into the protected snapshot.

For example, an order edit controller can declare:

```php
protected function requestPolicies(): array
{
    return [
        'save' => [
            'methods' => ['POST'],
            'editable' => ['note'],
            'protected' => ['id', 'price_cents', 'owner_id', 'role'],
        ],
    ];
}
```

In its edit action, load the order and current user from trusted storage, check ownership/access, then build the template data:

```php
$protected = [
    'id' => $order['id'],
    'price_cents' => $order['price_cents'],
    'owner_id' => $order['owner_id'],
    'role' => $currentUser['role'],
];
$token = $this->routeIntegrityToken('orders.save', $protected, ['id' => (string) $order['id']]);
$csrf = \Core\Security::generateCSRFToken();
```

Register the destination as a named route such as `$router->post('orders/save/{id:\d+}', [Orders::class, 'save'])->name('orders.save')`. The destination must have the same route parameters, for example `/orders/save/42`. Modern challenges bind to the named route, path, methods and route parameters, including when two URLs target the same controller action. If the destination has no ID parameter, pass `[]` instead. The method defaults to POST and the challenge expires after 15 minutes (configurable from 1 second to 1 hour). The session retains at most 64 outstanding challenges; issuing more invalidates the oldest forms. Legacy `Router::add()` controllers retain their action-based `integrityToken()` helper.

Echo all protected values and the two tokens in the form. Twig autoescapes these attributes:

```html
<form method="post" action="/orders/save/{{ protected.id }}">
    <input type="hidden" name="_csrf_token" value="{{ csrf }}">
    <input type="hidden" name="_integrity_token" value="{{ token }}">
    <input type="hidden" name="id" value="{{ protected.id }}">
    <input type="hidden" name="price_cents" value="{{ protected.price_cents }}">
    <input type="hidden" name="owner_id" value="{{ protected.owner_id }}">
    <input type="hidden" name="role" value="{{ protected.role }}">
    <textarea name="note"></textarea>
    <button type="submit">Save</button>
</form>
```

JSON requests send the same fields. Tokens can instead be supplied through `X-CSRF-Token` and `X-Integrity-Token`. Conflicting header/body tokens, query parameters on writes, unexpected fields, arrays/objects and modified/missing protected values are rejected. Prices must be integer cents or exact decimal strings, not floating point values. Integer values and their exact form string representations compare equally; the application receives the original trusted server value.

In the save action, use `$this->input()` for validated data. Do not fall back to `$_REQUEST`, `$_GET`, `$_POST` or reparsed raw input. Explicitly validate editable fields for length, format and business rules; the guard is not a replacement for those checks. Editable values must be finite scalars or JSON null.

Write bodies must be JSON objects or `application/x-www-form-urlencoded` forms. Their parser rejects duplicate field names, including differently encoded aliases, nested values, invalid names and invalid UTF-8. Query strings use the same strict form parser instead of PHP's field-name normalization. JSON numbers must be finite. Field names use letters, digits and underscores, starting with a letter or underscore; protected and editable policy fields start with a letter because underscore-prefixed security fields are reserved. Multipart requests and uploads are unsupported. Bodies and query strings are each limited to 1 MiB and 256 fields; configure the web server/PHP body limits consistently as well.

## Current database values and authorization

A valid snapshot proves what Iris issued to that session. It does not prove that a price, owner or role is still current. In the save action, start a database transaction, reload/lock the relevant records, check the current user's access again, then compare with the snapshot:

```php
$data = $this->input();
$current = [
    'id' => $lockedOrder['id'],
    'price_cents' => $lockedOrder['price_cents'],
    'owner_id' => $lockedOrder['owner_id'],
    'role' => $currentUser['role'],
];
\Core\DataIntegrity::assertCurrent($data, $current, ['id', 'price_cents', 'owner_id', 'role']);
```

`assertCurrent()` returns HTTP 409 when a protected value changed or disappeared. Use a transaction with row locking or a versioned conditional update to avoid changes between this comparison and the write. Update only explicitly editable columns with prepared statements. Never use submitted or snapshot roles to authorize access, assign ownership, or overwrite a database price. A role/ownership change requires its own authorized workflow and policy.

These snippets describe an application's order model; Iris deliberately supplies no order schema, login website or role repository. Its authentication and authorization middleware invoke application-supplied resolvers and policies. Adding request checks does not detect arbitrary database tampering outside protected requests. Database-at-rest integrity needs separate auditing/signing with a key outside the database and a defined threat model.

## Authenticated stateless routes

Mark API routes with `stateless()`, supply an editable/protected policy, and attach `Middleware\Authenticate` with a resolver that verifies the actual token or signature. Stateless processing requires the middleware's internal authenticated marker. Missing/failed authentication returns HTTP 401. Submitted protected fields return HTTP 403 rather than becoming trusted data; unexpected fields are rejected. Use `Authorize` and current database values to enforce per-record access. Do not mark a resolver successful merely because a header exists.

Structured JSON is an explicit application option, `http.structured_json => true`. Duplicate keys are checked recursively, including escaped aliases; depth is limited to 32, each collection to 256 entries and the body to 1 MiB. This option does not permit arrays through session integrity checks. Validate editable API collections recursively before persisting their values. URL-encoded forms and query strings remain flat.

## Session, errors and encryption changes

Sessions use strict IDs, cookie-only transport, HttpOnly and SameSite=Lax. Secure is enabled when PHP receives a direct HTTPS request. For TLS termination at a proxy, configure the trusted server layer to pass verified HTTPS state; Iris does not trust arbitrary forwarded headers. Call `Session::regenerate()` after login, logout/account changes or privilege changes; it rotates the ID and invalidates outstanding CSRF/integrity tokens. `Session::Destroy()` clears server state and expires the session cookie without redirecting.

Ordinary error responses no longer expose traces, SQL details or file paths, and explicit HTTP errors keep their status. Debug metadata requires both `IRIS_DEBUG=1` and `Config::SHOW_ERRORS=true`. Unexpected-error logging records exception type and location, without request payloads, credentials or tokens.

`Security::encryptData()` now produces authenticated `iris:v1:` AES-256-GCM envelopes. Changing the ciphertext, nonce or authentication tag makes decryption fail. Use at least 32 cryptographically random bytes for its secret key, held outside Git. Unauthenticated legacy AES-CBC values and keys shorter than 32 bytes are deliberately rejected. If an application has persisted older ciphertext, migrate it separately from a verified backup before adopting this change; no automatic legacy fallback is present.

Dependency auditing covers the active lockfile and `.iris/vendor/` installation. It does not certify old vendor copies, application business logic or external integrations. Live Google OAuth/API calls were not exercised during the dependency upgrade.

## Outgoing HTTP requests

`cCurl` accepts HTTP and HTTPS URLs, limits transfer/connection timeouts, and restricts redirect protocols. Its `callAPI()` method owns the HTTP method, destination, headers and body; use its explicit arguments instead of conflicting low-level options. Failed downloads preserve the existing destination file and clean up temporary files. Parallel transfer failures are represented by `false` for that request.

Applications must still authorize outgoing destinations when URLs originate from users. Protocol restrictions alone do not block requests to internal hosts or cloud metadata services. Use a host allowlist and appropriate network controls for such features. Live service calls remain separate from the local regression fixtures.

## Regression checks

`composer test` lints the application's PHP sources before running every top-level `tests/*.php` suite except `run.php`, using a separate process for each suite. It returns a nonzero exit code on a lint failure or failed suite. `composer lint` checks syntax alone; `php tests/run.php` runs the same default checks without the Composer command wrapper. These checks exclude generated dependency directories and require process execution and local loopback sockets.

The GitHub Actions workflow uses locked dependency installation with plugins and install scripts disabled, read-only repository permissions, and pinned action commits. It is configured to check PHP 8.3 and 8.4, Composer configuration and platform requirements, regression suites and known dependency advisories. No production credentials or database are required. CI configuration does not replace application-specific authorization tests or production deployment checks.

Design references: [OWASP mass assignment](https://cheatsheetseries.owasp.org/cheatsheets/Mass_Assignment_Cheat_Sheet.html), [authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html), and [CSRF prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).
