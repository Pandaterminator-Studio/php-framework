<?php

namespace Core\Testing;

use AssertionError;
use Core\Http\Response;
use JsonException;
use stdClass;

final class TestResponse
{
    public function __construct(private readonly Response $response)
    {
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function json(): mixed
    {
        return json_decode($this->response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    public function assertStatus(int $expected): self
    {
        $actual = $this->response->status();
        if ($actual !== $expected) {
            throw new AssertionError("Expected HTTP status {$expected}, received {$actual}.");
        }
        return $this;
    }

    public function assertHeader(string $name, string|array|null $expected = null): self
    {
        $actual = $this->response->header($name);
        if ($actual === null) {
            throw new AssertionError("Missing response header {$name}.");
        }
        if ($expected !== null && $actual !== $expected) {
            throw new AssertionError("Unexpected value for response header {$name}.");
        }
        return $this;
    }

    public function assertJson(mixed $expected): self
    {
        $encoded = json_encode($expected, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $document = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
        try {
            $body = $this->response->body();
            $actual = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $exactIntegers = json_decode($body, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new AssertionError('Response body does not contain valid JSON.', 0, $exception);
        }
        if (!self::sameJson($document, $actual) || !self::sameJson($document, $exactIntegers)) {
            throw new AssertionError('JSON response does not match the expected document.');
        }
        return $this;
    }

    private static function sameJson(mixed $expected, mixed $actual): bool
    {
        if ((is_int($expected) && is_float($actual)) || (is_float($expected) && is_int($actual))) {
            $integer = is_int($expected) ? $expected : $actual;
            $decimal = is_float($expected) ? $expected : $actual;
            return (float) $integer === $decimal && (int) $decimal === $integer;
        }
        if ($expected instanceof stdClass && $actual instanceof stdClass) {
            $expected = get_object_vars($expected);
            $actual = get_object_vars($actual);
        }
        if (!is_array($expected) || !is_array($actual)) {
            return $expected === $actual;
        }
        if (count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || !self::sameJson($value, $actual[$key])) {
                return false;
            }
        }
        return true;
    }
}
