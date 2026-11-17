# HTTP, middleware and views

Iris applications exchange `Core\Http\Request` and `Core\Http\Response` objects. These classes do not depend on the example application's configuration or template directory.

## Requests

```php
use Core\Http\Request;

$request = Request::fromGlobals();
$method = $request->method();
$path = $request->path();
$page = $request->query('page', '1');
$title = $request->body('title');
$accept = $request->header('Accept', 'text/html');
```

`fromGlobals()` uses the framework's strict request parser before capturing the actual headers, cookies, server parameters and original body bytes. It rejects ambiguous or duplicate input according to the same rules as `RequestGuard`.

HTTP adapters can call `Request::fromHttp($method, $uri, $headers, $rawBody)` with an origin-form URI and raw body bytes. The factory applies the same input parsing rules as PHP requests. Pass `structuredJson: true` to allow nested JSON. To run the whole application, call `$app->handleHttp($method, $uri, $headers, $rawBody)`. It applies the application's configuration and renders parse failures as HTTP responses before middleware runs. Supply cookies and trusted server metadata as the optional final arguments. For response assertions, use the [HTTP test client](testing.md).

The constructor accepts method, URI, headers, query, body, cookies, server parameters, attributes and raw body, in that order. A URI is an origin-form target beginning with a single `/`. `path()` excludes the query string and preserves percent encoding; `uri()` preserves the complete target. For manually constructed requests, supply parsed query and body arrays explicitly.

`query()` and `body()` return their complete arrays when called without a name. Named lookups preserve explicit null values and use the default only for missing keys. `headers()` contains lowercase names; `header()` is case-insensitive. `cookies()`, `server(name, default)`, `attributes()` and `attribute(name, default)` expose the other request data.

`withAttribute(name, value)` and `withBody(array)` return new requests. The original request is unchanged. Replacing the parsed body does not replace `rawBody()`, which remains the original received bytes. Avoid logging raw bodies, cookies or authorization headers.

`guard()` creates a guard from the request's current method, headers, query and parsed body. Request parsing and validation do not grant authorization or replace integrity policies.

`expectsJson()` recognizes accepted JSON media types, including `application/*+json`, or a JSON request content type. A JSON Accept entry with `q=0` does not itself request JSON.

```php
$validated = $request->validate([
    'title' => 'required|string|max:120',
]);
```

`validate()` validates the parsed body and returns declared, validated fields. It throws `Core\Validation\ValidationException` on failure. To validate query parameters, call `Core\Validation\Validator::validate($request->query(), $rules)` explicitly.

## Responses

```php
use Core\Http\Response;

$json = Response::json(['saved' => true], 201);
$html = Response::html('<p>Saved</p>');
$text = Response::text('Saved');
$redirect = Response::redirect('/items');
$response = $json->withHeader('X-Result', 'saved');
```

The constructor accepts a string body, HTTP status and associative headers. `body()`, `status()`, `headers()` and case-insensitive `header(name, default)` read the response. `withBody()`, `withStatus()` and `withHeader()` return clones.

Header names and values are validated; line breaks and other forbidden control characters are rejected. Multiple cookie fields use an array:

```php
$response = Response::text('Ready')->withHeader('Set-Cookie', [
    'first=one; Path=/; HttpOnly; SameSite=Lax',
    'second=two; Path=/; HttpOnly; SameSite=Lax',
]);
```

Redirects accept absolute local paths by default. Protocol-relative URLs and backslash variants are rejected. An external HTTP or HTTPS destination requires an explicit opt-in:

```php
$response = Response::redirect('https://example.org/return', allowExternal: true);
```

Apply an application allowlist before opting in when a redirect destination comes from user input.

`send($head)` emits the status, headers and body. Pass `true` for a HEAD request. Informational responses, 204 and 304 responses never emit a body. Send before any output; the method rejects responses whose headers have already been sent.

## Middleware

```php
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\Pipeline;

$pipeline = new Pipeline([
    static function (Request $request, callable $next): Response {
        $response = $next($request->withAttribute('request_source', 'web'));
        return $response->withHeader('X-Application', 'Example');
    },
]);

$response = $pipeline->handle($request, static function (Request $request): Response {
    return Response::text('Ready');
});
```

Middleware can be callable or implement `Core\Middleware\MiddlewareInterface::process(Request $request, callable $next): Response`. Entries run in registration order; response processing unwinds in reverse order. Returning a response without calling `$next` stops the chain. Middleware and destinations must return `Response` objects. Exceptions propagate to the application error boundary.

## Error responses and logging

```php
use Core\Http\ErrorHandler;

$handler = new ErrorHandler($psrLogger);
$response = $handler->response($exception, $request);
```

The logger is optional and follows PSR-3. Public `Core\HttpException` errors keep their explicit status and message. Validation failures return 422 with field errors; JSON responses use `message` and `errors` keys. HTML messages and field errors are escaped.

Unexpected exceptions return a generic 500 response. Error output does not include exception messages, traces, request values or configuration. Unexpected-error logs contain the exception class, source basename and line number, without the original message or request data. Logger failures do not replace the error response. Without a configured logger, this metadata goes to PHP's error log.

## Twig rendering

```php
use Core\View\Renderer;

$views = new Renderer(__DIR__ . '/templates');
$body = $views->render('items/show.html', ['item' => $item]);
$response = $views->response('items/show.html', ['item' => $item]);
```

`render()` returns a string; `response()` returns an HTML response and also accepts status and headers. The template directory belongs to the application. HTML autoescaping is enabled. Templates are application code; do not accept arbitrary user-authored Twig templates.

Template lookup rejects absolute paths, traversal, hidden segments, backslashes and symlinks. The same checks apply to Twig includes and inheritance. Keep templates in a dedicated directory rather than mixing them with secrets or configuration.

The optional second constructor argument is a Twig cache directory; the default `false` disables filesystem caching:

```php
$views = new Renderer(__DIR__ . '/templates', __DIR__ . '/storage/template-cache');
```
