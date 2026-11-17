<?php

namespace Core;

use InvalidArgumentException;

final class RequestGuard
{
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private string $method;
    private array $query;
    private array $body;
    private array $headers;

    public function __construct(string $method, array $query = [], array $body = [], array $headers = [])
    {
        $this->method = strtoupper($method);
        $this->query = $query;
        $this->body = $body;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match("/^[!#$%&'*+.^_`|~0-9a-z-]+$/iD", $name) !== 1
                || ($value !== null && !is_string($value))
                || (is_string($value) && (str_contains($value, "\r") || str_contains($value, "\n")))) {
                throw new HttpException(400, 'Invalid request header.');
            }
            $name = strtolower($name);
            if (array_key_exists($name, $this->headers)) {
                throw new HttpException(400, 'Duplicate request header.');
            }
            $this->headers[$name] = $value;
        }
    }

    public static function fromGlobals(bool $structuredJson = false): self
    {
        $request = Http\Request::fromGlobals($structuredJson);
        return new self($request->method(), $request->query(), $request->body(), [
            'x-csrf-token' => $request->header('x-csrf-token'),
            'x-integrity-token' => $request->header('x-integrity-token'),
        ]);
    }

    public function validate(string $scope, array $routeValues, array $policy, bool $stateless = false, bool $authenticated = false): array
    {
        $methods = array_key_exists('methods', $policy) ? $policy['methods'] : ['GET', 'HEAD'];
        $editable = array_key_exists('editable', $policy) ? $policy['editable'] : [];
        $protected = array_key_exists('protected', $policy) ? $policy['protected'] : null;
        $integrity = array_key_exists('integrity', $policy) ? $policy['integrity'] : false;
        if (array_diff(array_keys($policy), ['methods', 'editable', 'protected', 'integrity'])
            || !is_array($methods) || !array_is_list($methods) || $methods === []
            || array_filter($methods, static fn($method) => !is_string($method) || !in_array($method, self::METHODS, true))
            || count(array_unique($methods, SORT_STRING)) !== count($methods)
            || !self::validFields($editable)
            || (array_key_exists('protected', $policy) && !self::validFields($protected))
            || !is_bool($integrity) || array_intersect($editable, $protected ?? [])) {
            throw new InvalidArgumentException('Invalid request policy.');
        }
        if (!in_array($this->method, $methods, true)) {
            throw new HttpException(405, 'HTTP method is not allowed for this action.');
        }
        $writing = !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
        if ($writing && $this->query !== []) {
            throw new HttpException(400, 'Write request parameters must be sent in the body.');
        }
        if (!$writing && $this->body !== []) {
            throw new HttpException(400, 'Read requests cannot contain form data.');
        }
        $input = $writing ? $this->body : $this->query;
        if ($stateless) {
            if (!$authenticated) {
                throw new HttpException(401, 'Authentication is required for stateless routes.');
            }
            if ($protected === null) {
                throw new InvalidArgumentException('Stateless routes must declare protected and editable fields.');
            }
            foreach ($input as $name => $value) {
                if (in_array($name, $protected, true)) {
                    throw new HttpException(403, 'Protected fields cannot be submitted to this route.');
                }
                if (!in_array($name, $editable, true)) {
                    throw new HttpException(400, 'Unexpected request field.');
                }
            }
            return $input;
        }
        if ($writing && !Security::validateCSRFToken($this->token('x-csrf-token', '_csrf_token', $input))) {
            throw new HttpException(403, 'Missing or invalid CSRF token.');
        }
        if ($writing || $integrity || $protected !== null && $protected !== []) {
            if ($protected === null) {
                throw new InvalidArgumentException('Integrity-protected actions must declare their protected fields.');
            }
            $token = $this->token('x-integrity-token', '_integrity_token', $input);
            unset($input['_csrf_token'], $input['_integrity_token']);
            return DataIntegrity::verify($token, $scope, $this->method, $routeValues, $input, $editable, $protected);
        }
        return $input;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function query(): array
    {
        return $this->query;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    private static function validFields($fields): bool
    {
        return is_array($fields) && array_is_list($fields)
            && !array_filter($fields, static fn($name) => !is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name))
            && count(array_unique($fields, SORT_STRING)) === count($fields);
    }

    private function token(string $header, string $field, array $input)
    {
        $headerValue = $this->headers[$header] ?? null;
        $fieldValue = $input[$field] ?? null;
        if ($headerValue !== null && $fieldValue !== null && $headerValue !== $fieldValue) {
            throw new HttpException(400, 'Conflicting security tokens.');
        }
        return $headerValue ?? $fieldValue;
    }
}
