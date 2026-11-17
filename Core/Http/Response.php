<?php

namespace Core\Http;

final class Response
{
    private string $body;
    private int $status;
    private array $headers = [];

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        self::validateStatus($status);
        $this->body = $body;
        $this->status = $status;
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException('Response header names must be strings.');
            }
            $this->setHeader($name, $value);
        }
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status,
            array_merge(['Content-Type' => 'application/json; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff'], $headers));
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, array_merge(['Content-Type' => 'text/html; charset=UTF-8'], $headers));
    }

    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, array_merge(['Content-Type' => 'text/plain; charset=UTF-8'], $headers));
    }

    public static function redirect(string $location, int $status = 302, array $headers = [], bool $allowExternal = false): self
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $location)) {
            throw new \InvalidArgumentException('Invalid redirect destination or status.');
        }
        $decoded = rawurldecode($location);
        $local = $location[0] === '/' && !str_starts_with($location, '//') && !str_starts_with($decoded, '//')
            && !str_contains($decoded, '\\');
        if (!$local) {
            $parts = parse_url($location);
            if (!$allowExternal || $parts === false || !isset($parts['scheme'], $parts['host'])
                || $parts['host'] === '' || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                throw new \InvalidArgumentException('External redirects require an explicit HTTP or HTTPS destination.');
            }
        }
        return new self('', $status, array_merge($headers, ['Location' => $location]));
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function withHeader(string $name, mixed $value): self
    {
        $response = clone $this;
        $response->setHeader($name, $value);
        return $response;
    }

    public function withStatus(int $status): self
    {
        self::validateStatus($status);
        $response = clone $this;
        $response->status = $status;
        return $response;
    }

    public function withBody(string $body): self
    {
        $response = clone $this;
        $response->body = $body;
        return $response;
    }

    public function send(bool $head = false): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('Response headers have already been sent.');
        }
        http_response_code($this->status);
        foreach ($this->headers as $name => $values) {
            if (($this->status < 200 || $this->status === 204) && in_array($name, ['content-length', 'transfer-encoding'], true)) {
                continue;
            }
            foreach (is_array($values) ? $values : [$values] as $position => $value) {
                header($name . ': ' . $value, $name !== 'set-cookie' && $position === 0);
            }
        }
        if (!$head && $this->status >= 200 && $this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }

    private static function validateStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('HTTP response status must be between 100 and 599.');
        }
    }

    private function setHeader(string $name, mixed $value): void
    {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9a-z-]+$/iD", $name) !== 1
            || (!is_string($value) && !is_array($value)) || (is_array($value) && (!array_is_list($value) || $value === []))) {
            throw new \InvalidArgumentException('Invalid response header.');
        }
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (!is_string($entry) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $entry)) {
                throw new \InvalidArgumentException('Invalid response header value.');
            }
        }
        $this->headers[strtolower($name)] = $value;
    }
}
