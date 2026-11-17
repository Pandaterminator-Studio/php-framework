<?php

namespace Core;

use JsonException;

final class RequestBody
{
    public const MAX_BYTES = 1048576;
    public const MAX_FIELDS = 256;

    public static function json(string $raw, bool $structured = false): array
    {
        self::checkSize($raw);
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new HttpException(400, 'Invalid JSON request body.');
        }
        if (!$decoded instanceof \stdClass) {
            throw new HttpException(400, 'JSON request body must be an object.');
        }
        $values = get_object_vars($decoded);
        if (count($values) > self::MAX_FIELDS) {
            throw new HttpException(400, 'Too many request fields.');
        }
        foreach ($values as $name => $value) {
            self::checkName($name);
            $values[$name] = self::jsonValue($value, $structured);
        }
        self::checkJsonKeys($raw, $structured);
        return $values;
    }

    public static function form(string $raw): array
    {
        self::checkSize($raw);
        if ($raw === '') {
            return [];
        }
        if (substr_count($raw, '&') >= self::MAX_FIELDS) {
            throw new HttpException(400, 'Too many request fields.');
        }
        $values = [];
        foreach (explode('&', $raw) as $field) {
            if ($field === '') {
                continue;
            }
            if (preg_match('/%(?![a-f0-9]{2})/i', $field)) {
                throw new HttpException(400, 'Invalid form encoding.');
            }
            $parts = explode('=', $field, 2);
            $name = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            self::checkName($name);
            if (preg_match('//u', $value) !== 1) {
                throw new HttpException(400, 'Request fields must use UTF-8 encoding.');
            }
            if (array_key_exists($name, $values)) {
                throw new HttpException(400, 'Duplicate request fields are not allowed.');
            }
            $values[$name] = $value;
        }
        return $values;
    }

    private static function checkSize(string $raw): void
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new HttpException(413, 'Request body is too large.');
        }
    }

    private static function checkName($name): void
    {
        if (!is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            throw new HttpException(400, 'Invalid request field name.');
        }
    }

    private static function jsonValue(mixed $value, bool $structured): mixed
    {
        if (is_array($value) || $value instanceof \stdClass) {
            if (!$structured) {
                throw new HttpException(400, 'Request fields must be finite scalar values.');
            }
            $values = is_array($value) ? $value : get_object_vars($value);
            if (count($values) > self::MAX_FIELDS) {
                throw new HttpException(400, 'Too many request fields.');
            }
            foreach ($values as $key => $item) {
                $values[$key] = self::jsonValue($item, true);
            }
            return $values;
        }
        if (is_float($value) && !is_finite($value)) {
            throw new HttpException(400, 'Request fields must be finite scalar values.');
        }
        return $value;
    }

    private static function checkJsonKeys(string $raw, bool $structured): void
    {
        $offset = 0;
        self::skipWhitespace($raw, $offset);
        self::scanJsonValue($raw, $offset, $structured, true);
    }

    private static function scanJsonValue(string $raw, int &$offset, bool $structured, bool $root = false): void
    {
        self::skipWhitespace($raw, $offset);
        $kind = $raw[$offset];
        if ($kind === '"') {
            self::skipString($raw, $offset);
            return;
        }
        if ($kind !== '{' && $kind !== '[') {
            $length = strcspn($raw, ",]} \r\n\t", $offset);
            if ($length === 0) {
                throw new HttpException(400, 'Invalid JSON request body.');
            }
            $offset += $length;
            return;
        }
        if (!$root && !$structured) {
            throw new HttpException(400, 'Request fields must be finite scalar values.');
        }
        $object = $kind === '{';
        $end = $object ? '}' : ']';
        $offset++;
        self::skipWhitespace($raw, $offset);
        $seen = [];
        $count = 0;
        while (isset($raw[$offset]) && $raw[$offset] !== $end) {
            if (++$count > self::MAX_FIELDS) {
                throw new HttpException(400, 'Too many request fields.');
            }
            if ($object) {
                $start = $offset;
                self::skipString($raw, $offset);
                $name = json_decode(substr($raw, $start, $offset - $start), false, 2, JSON_THROW_ON_ERROR);
                if (array_key_exists($name, $seen)) {
                    throw new HttpException(400, 'Duplicate request fields are not allowed.');
                }
                $seen[$name] = true;
                self::skipWhitespace($raw, $offset);
                if (($raw[$offset] ?? null) !== ':') {
                    throw new HttpException(400, 'Invalid JSON request body.');
                }
                $offset++;
            }
            self::scanJsonValue($raw, $offset, $structured);
            self::skipWhitespace($raw, $offset);
            if (($raw[$offset] ?? null) !== $end) {
                if (($raw[$offset] ?? null) !== ',') {
                    throw new HttpException(400, 'Invalid JSON request body.');
                }
                $offset++;
                self::skipWhitespace($raw, $offset);
            }
        }
        if (($raw[$offset] ?? null) !== $end) {
            throw new HttpException(400, 'Invalid JSON request body.');
        }
        $offset++;
    }

    private static function skipString(string $raw, int &$offset): void
    {
        if (($raw[$offset] ?? null) !== '"') {
            throw new HttpException(400, 'Invalid JSON request body.');
        }
        $offset++;
        while (isset($raw[$offset]) && $raw[$offset] !== '"') {
            if ($raw[$offset] === '\\') {
                $offset++;
            }
            $offset++;
        }
        if (($raw[$offset] ?? null) !== '"') {
            throw new HttpException(400, 'Invalid JSON request body.');
        }
        $offset++;
    }

    private static function skipWhitespace(string $raw, int &$offset): void
    {
        $offset += strspn($raw, " \n\r\t", $offset);
    }
}
