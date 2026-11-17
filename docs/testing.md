# Testing an application

`Core\Testing\TestClient` sends requests to an Iris application in the current PHP process. Use the same routes, service providers and middleware as your application. Point the test bootstrap at isolated fixtures for any databases, caches or external services that your handlers use.

```php
<?php

use Core\Testing\TestClient;

$app = require dirname(__DIR__) . '/bootstrap.php';
$client = new TestClient($app);

$client->get('/records/42?view=summary')
    ->assertStatus(200)
    ->assertJson(['id' => 42, 'view' => 'summary']);
```

The examples assume your test application has defined the illustrated routes and a record with ID 42. A newly generated Iris application has an empty route table.

## Testing JSON writes

Pass a credential recognized by your application's test authentication provider. The request goes through authentication, authorization, validation and request policies configured for that route.

```php
$client->json('PATCH', '/records/42', ['label' => 'Updated'], [
    'Authorization' => 'Bearer ' . $fixtureToken,
])->assertStatus(200)->assertHeader('Content-Type', 'application/json; charset=UTF-8');

$client->json('PATCH', '/records/42', ['label' => 'Updated'])->assertStatus(401);
```

`json()` encodes its field map as a JSON object and supplies `Accept` and `Content-Type` with the value `application/json` by default. Request parsing uses the application's `http.structured_json` setting. Headers, cookies and request attributes from the previous call expire between calls. The application instance and its services are shared. Create a fresh application for each test that requires isolated service state. PHP's native session state also has process lifetime.

Use `request($method, $uri, $headers, $rawBody, $cookies, $server)` for raw bytes, explicit cookies or server metadata. The last four arguments are optional. The client uses loopback as the default `REMOTE_ADDR`. Put query parameters in the URI. Parser errors produce the same negotiated error response as HTTP input to the application.

```php
$client->request('PATCH', '/records/42', ['Content-Type' => 'application/json'], '{')
    ->assertStatus(400);
```

## Inspecting responses

- `assertStatus(200)` checks the exact status.
- `assertHeader('X-Request-Id')` checks presence. Add a string or list to check an exact value. Header names are case-insensitive.
- `assertJson($expected)` compares the complete JSON document. Object member order is ignored. List order and scalar types are checked. Use `(object) []` for an empty object and `[]` for an empty list.
- `json()` decodes a response for individual assertions. Objects become associative arrays and oversized integers become strings. Invalid JSON raises `JsonException`.
- `response()` returns the framework's underlying `Core\Http\Response` for inspecting body bytes, redirects or multiple header values.

Assertion failures throw `AssertionError` even when PHP assertions are disabled. The client returns the kernel response before PHP sends headers and the body. Use an actual HTTP server and client for browser cookie handling, redirects across requests, multipart uploads, web-server rules and bytes emitted by HEAD or bodyless status responses.

JSON integers within PHP's integer range retain their precision. A fraction or exponent uses PHP floating-point precision. `assertJson()` rejects an unquoted integer literal outside PHP's integer range, even if a float rounds to its value or a string contains its digits. Use `json()` to read its full digits or `response()->body()` to check its wire representation.
