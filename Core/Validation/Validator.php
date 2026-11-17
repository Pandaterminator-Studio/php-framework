<?php

namespace Core\Validation;

use DateTimeImmutable;
use InvalidArgumentException;

final class Validator
{
    private const RULES = ['required', 'sometimes', 'nullable', 'array', 'string', 'integer', 'numeric', 'boolean', 'email', 'url', 'min', 'max', 'between', 'in', 'confirmed', 'same', 'date', 'regex'];

    public static function validate(array $input, array $rules): array
    {
        $result = self::check($input, $rules);
        if (!$result->valid()) {
            throw new ValidationException($result->errors());
        }
        return $result->data();
    }

    public static function check(array $input, array $rules): ValidationResult
    {
        return self::checkFields($input, self::fieldRules($rules), '');
    }

    private static function fieldRules(array $rules): array
    {
        $fields = [];
        foreach ($rules as $field => $definition) {
            if (!is_string($field) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field) !== 1) {
                throw new InvalidArgumentException('Validation fields must have flat letter, digit and underscore names.');
            }
            $fields[$field] = self::rules($definition);
        }
        return $fields;
    }

    private static function checkFields(array $input, array $fields, string $prefix): ValidationResult
    {
        $data = [];
        $errors = [];
        foreach ($fields as $key => $definition) {
            $field = (string) $key;
            $path = $prefix . $field;
            $constraints = $definition['checks'];
            $names = array_column($constraints, 'name');
            $present = array_key_exists($field, $input);
            if (!$present && in_array('sometimes', $names, true)) {
                continue;
            }
            $value = $present ? $input[$field] : null;
            if (in_array('required', $names, true) && (!$present || self::empty($value))) {
                $errors[$path][] = 'The ' . $path . ' field is required.';
                continue;
            }
            if (!$present) {
                continue;
            }
            if ($value === null && in_array('nullable', $names, true)) {
                $data[$field] = null;
                continue;
            }
            $numeric = in_array('numeric', $names, true) || in_array('integer', $names, true);
            foreach ($constraints as $constraint) {
                $name = $constraint['name'];
                if (in_array($name, ['required', 'sometimes', 'nullable'], true)) {
                    continue;
                }
                if ($name === 'custom') {
                    $outcome = ($constraint['callback'])($value, $input, $field);
                    if (!is_bool($outcome) && !is_string($outcome)) {
                        throw new InvalidArgumentException('Custom validation rules must return a boolean or an error message.');
                    }
                    if ($outcome !== true) {
                        $errors[$path][] = is_string($outcome) && $outcome !== '' ? $outcome : 'The ' . $path . ' field is invalid.';
                    }
                } elseif (!self::passes($name, $constraint['parameters'], $value, $input, $field, $numeric)) {
                    $errors[$path][] = self::message($path, $name);
                }
            }
            if (isset($errors[$path])) {
                continue;
            }
            if ($definition['shape'] !== null) {
                $nested = self::checkShape($value, $definition['shape'], $path);
                if (!$nested->valid()) {
                    $errors += $nested->errors();
                    continue;
                }
                $value = $nested->data();
            }
            $data[$field] = $value;
        }
        return new ValidationResult($data, $errors);
    }

    private static function checkShape(mixed $value, array $shape, string $path): ValidationResult
    {
        if ($shape['list']) {
            if (!is_array($value) || !array_is_list($value)) {
                return new ValidationResult([], [$path => ['The ' . $path . ' field must be a list.']]);
            }
            $fields = array_fill(0, count($value), $shape['rules']);
        } else {
            if (!is_array($value) || array_filter(array_keys($value), 'is_int') !== []) {
                return new ValidationResult([], [$path => ['The ' . $path . ' field must be an object with named fields.']]);
            }
            $fields = $shape['rules'];
        }
        return self::checkFields($value, $fields, $path . '.');
    }

    private static function rules($definition): array
    {
        if ($definition instanceof Shape) {
            $definition = [$definition];
        } elseif (is_string($definition)) {
            $definition = $definition === '' ? [] : explode('|', $definition);
        }
        if (!is_array($definition) || !array_is_list($definition)) {
            throw new InvalidArgumentException('Validation rules must be a string, shape or list of rules.');
        }
        $rules = [];
        $shape = null;
        foreach ($definition as $rule) {
            if ($rule instanceof Shape) {
                if ($shape !== null) {
                    throw new InvalidArgumentException('A field can have only one validation shape.');
                }
                $shape = [
                    'list' => $rule->isList,
                    'rules' => $rule->isList ? self::rules($rule->rules) : self::fieldRules($rule->rules),
                ];
                continue;
            }
            if (!is_string($rule) && is_callable($rule)) {
                $rules[] = ['name' => 'custom', 'callback' => $rule];
                continue;
            }
            if (!is_string($rule)) {
                throw new InvalidArgumentException('Validation rules must be strings, shapes or callables.');
            }
            $parts = explode(':', $rule, 2);
            $name = $parts[0];
            if (!in_array($name, self::RULES, true)) {
                throw new InvalidArgumentException('Unknown validation rule.');
            }
            $raw = $parts[1] ?? null;
            $parameters = $raw === null ? [] : ($name === 'regex' ? [$raw] : explode(',', $raw));
            $count = count($parameters);
            if ((in_array($name, ['min', 'max', 'same', 'regex'], true) && $count !== 1)
                || ($name === 'between' && $count !== 2) || ($name === 'in' && ($count === 0 || $raw === ''))
                || ($name === 'date' && ($count > 1 || $raw === ''))
                || (!in_array($name, ['min', 'max', 'between', 'in', 'same', 'regex', 'date'], true) && $count !== 0)) {
                throw new InvalidArgumentException('Invalid validation rule parameters.');
            }
            if (in_array($name, ['min', 'max', 'between'], true)) {
                foreach ($parameters as $parameter) {
                    if (!self::numeric($parameter)) {
                        throw new InvalidArgumentException('Validation bounds must be finite numbers.');
                    }
                }
                if ($name === 'between' && self::number($parameters[0]) > self::number($parameters[1])) {
                    throw new InvalidArgumentException('Validation bounds must be ordered.');
                }
            }
            if ($name === 'same' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $parameters[0]) !== 1) {
                throw new InvalidArgumentException('The same rule requires a valid comparison field.');
            }
            if ($name === 'regex' && @preg_match($parameters[0], '') === false) {
                throw new InvalidArgumentException('The regex rule requires a valid delimited expression.');
            }
            $rules[] = ['name' => $name, 'parameters' => $parameters];
        }
        return ['checks' => $rules, 'shape' => $shape];
    }

    private static function passes(string $rule, array $parameters, $value, array $input, string $field, bool $numeric): bool
    {
        return match ($rule) {
            'array' => is_array($value),
            'string' => is_string($value) && preg_match('//u', $value) === 1,
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1 && filter_var($value, FILTER_VALIDATE_INT) !== false),
            'numeric' => self::numeric($value),
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1'], true),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true),
            'min' => self::size($value, $numeric) !== null && self::size($value, $numeric) >= self::number($parameters[0]),
            'max' => self::size($value, $numeric) !== null && self::size($value, $numeric) <= self::number($parameters[0]),
            'between' => self::size($value, $numeric) !== null && self::size($value, $numeric) >= self::number($parameters[0])
                && self::size($value, $numeric) <= self::number($parameters[1]),
            'in' => (is_string($value) || is_int($value) || is_float($value)) && in_array((string) $value, $parameters, true),
            'confirmed' => array_key_exists($field . '_confirmation', $input) && $input[$field . '_confirmation'] === $value,
            'same' => array_key_exists($parameters[0], $input) && $input[$parameters[0]] === $value,
            'date' => self::date($value, $parameters[0] ?? 'Y-m-d'),
            'regex' => is_string($value) && @preg_match($parameters[0], $value) === 1,
        };
    }

    private static function numeric($value): bool
    {
        return (is_int($value) || is_float($value) || (is_string($value) && $value !== '' && trim($value) === $value))
            && is_numeric($value) && is_finite((float) $value);
    }

    private static function size($value, bool $numeric): int|float|null
    {
        if ($numeric || is_int($value) || is_float($value)) {
            return self::numeric($value) ? self::number($value) : null;
        }
        if (is_string($value)) {
            $length = preg_match_all('/./us', $value);
            return $length === false ? null : $length;
        }
        return is_array($value) ? count($value) : null;
    }

    private static function number($value): int|float
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                return $integer;
            }
        }
        return (float) $value;
    }

    private static function date($value, string $format): bool
    {
        if (!is_string($value) || str_contains($value, "\0")) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format) === $value;
    }

    private static function empty($value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    private static function message(string $field, string $rule): string
    {
        $description = match ($rule) {
            'array' => 'must be an array',
            'string' => 'must be a UTF-8 string',
            'integer' => 'must be an integer',
            'numeric' => 'must be a finite number',
            'boolean' => 'must be a boolean',
            'email' => 'must be a valid email address',
            'url' => 'must be an absolute HTTP or HTTPS URL',
            'min' => 'is below the allowed minimum',
            'max' => 'exceeds the allowed maximum',
            'between' => 'is outside the allowed bounds',
            'in' => 'must be one of the allowed values',
            'confirmed' => 'does not match its confirmation',
            'same' => 'does not match the comparison field',
            'date' => 'must be a valid date in the required format',
            'regex' => 'has an invalid format',
        };
        return 'The ' . $field . ' field ' . $description . '.';
    }
}
