<?php

namespace Core\Http;

use Core\HttpException;
use Core\RequestBody;
use Core\RequestGuard;
use Core\Validation\Validator;

final class Request
{
    private string $method;
    private string $uri;
    private array $headers;
    private array $query;
    private array $body;
    private array $cookies;
    private array $server;
    private array $attributes;
    private string $rawBody;

    public function __construct(string $method, string $uri = '/', array $headers = [], array $query = [], array $body = [], array $cookies = [], array $server = [], array $attributes = [], string $rawBody = '')
    {
        $method = strtoupper($method);
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Z-]+$/D", $method) !== 1 || $uri === '' || $uri[0] !== '/'
            || str_starts_with($uri, '//') || preg_match('/[\x00-\x20\x7f\\\\#]/', $uri)
            || preg_match('/%(?![a-f0-9]{2})/i', $uri)) {
            throw new HttpException(400, 'Invalid HTTP request target or method.');
        }
        $this->method = $method;
        $this->uri = $uri;
        $this->headers = (new RequestGuard($method, $query, $body, $headers))->headers();
        $this->query = $query;
        $this->body = $body;
        $this->cookies = $cookies;
        $this->server = $server;
        $this->attributes = $attributes;
        $this->rawBody = $rawBody;
    }

    public static function fromGlobals(bool $structuredJson = false): self
    {
        if (!empty($_FILES)) {
            throw new HttpException(400, 'File uploads require a separate upload policy.');
        }
        if (array_key_exists('_method', $_POST) || isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            throw new HttpException(400, 'HTTP method overrides are not supported.');
        }
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = is_array($headers) ? (new RequestGuard('GET', [], [], $headers))->headers() : [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $header = strtolower(str_replace('_', '-', substr($name, 5)));
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $header = strtolower(str_replace('_', '-', $name));
            } else {
                continue;
            }
            if (!array_key_exists($header, $headers)) {
                $headers[$header] = $value;
            }
        }
        $rawBody = file_get_contents('php://input', false, null, 0, RequestBody::MAX_BYTES + 1);
        if ($rawBody === false) {
            throw new HttpException(413, 'Request body is too large.');
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $request = new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $uri, $headers, cookies: $_COOKIE, server: $_SERVER, rawBody: $rawBody);
        $request->parseInput($_SERVER['QUERY_STRING'] ?? (explode('?', $uri, 2)[1] ?? ''), $structuredJson);
        return $request;
    }

    public static function fromHttp(string $method, string $uri, array $headers = [], string $rawBody = '', bool $structuredJson = false, array $cookies = [], array $server = []): self
    {
        $request = new self($method, $uri, $headers, cookies: $cookies, server: $server, rawBody: $rawBody);
        $request->parseInput(explode('?', $uri, 2)[1] ?? '', $structuredJson);
        return $request;
    }

    private function parseInput(string $queryString, bool $structuredJson): void
    {
        $length = $this->header('content-length', '0');
        if (!is_string($length) || !ctype_digit($length)) {
            throw new HttpException(400, 'Invalid request content length.');
        }
        if ((float) $length > RequestBody::MAX_BYTES || strlen($this->rawBody) > RequestBody::MAX_BYTES) {
            throw new HttpException(413, 'Request body is too large.');
        }
        $this->query = RequestBody::form($queryString);
        if ($this->header('x-http-method-override') !== null || array_key_exists('_method', $this->query)) {
            throw new HttpException(400, 'HTTP method overrides are not supported.');
        }
        if (in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if ($this->rawBody !== '') {
                throw new HttpException(400, 'Read requests cannot contain a body.');
            }
            return;
        }
        $contentType = strtolower(trim(explode(';', $this->header('content-type', ''))[0]));
        $this->body = match ($contentType) {
            'application/json' => RequestBody::json($this->rawBody, $structuredJson),
            'application/x-www-form-urlencoded' => RequestBody::form($this->rawBody),
            '' => $this->rawBody === '' ? [] : throw new HttpException(415, 'A request content type is required.'),
            default => throw new HttpException(415, 'Unsupported request content type.'),
        };
        if (array_key_exists('_method', $this->body)) {
            throw new HttpException(400, 'HTTP method overrides are not supported.');
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return explode('?', $this->uri, 2)[0];
    }

    public function query(?string $name = null, mixed $default = null): mixed
    {
        return $name === null ? $this->query : (array_key_exists($name, $this->query) ? $this->query[$name] : $default);
    }

    public function body(?string $name = null, mixed $default = null): mixed
    {
        return $name === null ? $this->body : (array_key_exists($name, $this->body) ? $this->body[$name] : $default);
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    public function server(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->server) ? $this->server[$name] : $default;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $request = clone $this;
        $request->attributes[$name] = $value;
        return $request;
    }

    public function withBody(array $body): self
    {
        $request = clone $this;
        $request->body = $body;
        return $request;
    }

    public function guard(): RequestGuard
    {
        return new RequestGuard($this->method, $this->query, $this->body, $this->headers);
    }

    public function expectsJson(): bool
    {
        foreach (explode(',', $this->header('accept', '')) as $accepted) {
            $parts = explode(';', strtolower(trim($accepted)));
            $mediaType = array_shift($parts);
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $parameter, $matches)) {
                    $quality = is_numeric($matches[1]) ? (float) $matches[1] : 0.0;
                }
            }
            if ($quality > 0 && $quality <= 1 && self::jsonMediaType($mediaType)) {
                return true;
            }
        }
        return self::jsonMediaType(strtolower(trim(explode(';', $this->header('content-type', ''))[0])));
    }

    public function validate(array $rules): array
    {
        return Validator::validate($this->body, $rules);
    }

    private static function jsonMediaType(string $mediaType): bool
    {
        return $mediaType === 'application/json' || $mediaType === 'text/json'
            || preg_match('~^application/[a-z0-9!#$&^_.+-]+\+json$~D', $mediaType) === 1;
    }
}
