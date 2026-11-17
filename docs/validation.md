# Validation

`Core\Validation\Validator` checks application input after request parsing and security checks. It does not authorize users, escape HTML, sanitize SQL, coerce values or replace the protected-field request policy.

```php
use Core\Validation\Validator;

$data = Validator::validate($input, [
    'email' => 'required|string|email|max:254',
    'age' => 'sometimes|integer|between:18,120',
    'note' => 'nullable|string|max:1000',
    'password' => 'required|string|min:12|confirmed',
]);
```

Only declared fields that were present and passed their rules are returned, with their original types and values. Unexpected input keys are excluded. Field names are flat names containing letters, digits and underscores, beginning with a letter or underscore. Missing optional fields are omitted rather than being filled with null. Empty rule strings or lists explicitly permit a declared field without further checks.

`validate()` throws `ValidationException`, an HTTP 422 exception whose `errors()` method returns an array of field names to lists of error messages. It does not include submitted values in built-in error messages. To inspect validation without throwing:

```php
$result = Validator::check($input, ['email' => 'required|email']);
if (!$result->valid()) {
    $errors = $result->errors();
}
$validFields = $result->data();
```

On failure, `data()` contains only the declared fields that passed, not a partially validated value for a failing field. Use `validate()` when an operation must reject the entire payload if any field fails.

## Rules

| Rule | Behavior |
| --- | --- |
| `required` | Rejects missing values, null, empty arrays and blank strings. Zero and false remain valid values. |
| `sometimes` | Skips all rules when the field is absent, including `required`. |
| `nullable` | Allows a present null and skips subsequent value rules. It does not override `required`. |
| `array` | Requires a PHP array. It does not validate its keys or nested values automatically. |
| `string` | Requires a valid UTF-8 string. |
| `integer` | Accepts PHP integers or canonical signed decimal strings within the PHP integer range; rejects leading zeros, surrounding whitespace, booleans and floats. |
| `numeric` | Accepts finite integers, floats and numeric strings without surrounding whitespace; booleans are rejected. |
| `boolean` | Accepts true, false, 0, 1, `"0"` and `"1"`; it does not convert them. |
| `email` | Checks the address format with PHP's email filter; it does not perform DNS or delivery checks. |
| `url` | Requires an absolute HTTP or HTTPS URL. This is not an outgoing-host authorization or SSRF check. |
| `min:n`, `max:n`, `between:a,b` | Compare numeric ranges when `integer` or `numeric` is declared, or the value is a PHP number. Otherwise use string code-point length or array item count. Bounds are inclusive. |
| `in:one,two` | Checks an exact string representation against the allowed values. Accepts string and numeric input, not booleans or arrays. |
| `confirmed` | Requires an identical value in `field_confirmation`; the confirmation is excluded from returned data unless separately declared. |
| `same:other` | Requires strict equality with another input field. |
| `date` | Requires an actual calendar date formatted as `Y-m-d`, without normalization of invalid dates. `date:d/m/Y` supplies another exact PHP date format. |
| `regex:/pattern/` | Requires a string matching a valid delimited PHP regular expression. |

Present empty strings are checked normally unless `required` rejects them first. `nullable` accepts null, not empty strings. Numeric validation uses PHP's native integer and floating-point ranges; use integer minor units for money rather than floating-point amounts. String lengths count Unicode code points, not grapheme clusters.

Use array rule syntax when a regular expression contains pipes:

```php
$data = Validator::validate($input, [
    'colour' => ['required', 'string', 'regex:/^(red|blue)$/'],
]);
```

Custom callables in rule lists receive the value, complete input and field name. Return true on success, false for a generic field error, or an application-controlled error message. They run only for present fields that have not been skipped by `nullable`.

```php
$data = Validator::validate($input, [
    'quantity' => ['required', 'integer', static fn($value, array $input, string $field): bool => (int) $value % 2 === 0],
]);
```

Keep secrets and submitted values out of custom error messages. Avoid expensive or user-controlled regular expressions. Invalid rule definitions, unknown rules, malformed regex patterns and invalid bounds throw `InvalidArgumentException`, identifying an application configuration error rather than an ordinary invalid submission.

## Structured input

Use `Core\Validation\Shape::object($fields)` to declare the fields of a nested record. Use `Shape::listOf($itemRules)` to apply one definition to every list element. Both work in `Validator::validate()`, `Validator::check()` and `$request->validate()`. To receive nested JSON over HTTP, enable `http.structured_json` in the application. Session-bound protected-data forms still require scalar fields.

```php
use Core\Validation\Shape;
use Core\Validation\Validator;

$line = Shape::object([
    'sku' => 'required|string|max:100',
    'quantity' => 'required|integer|min:1',
    'tags' => ['max:10', Shape::listOf('required|string|max:40')],
]);

$data = Validator::validate($input, [
    'lines' => ['required', 'max:100', Shape::listOf($line)],
]);
```

Each object returns only its declared fields. In this example, a submitted `unit_price` inside a line is omitted. An invalid quantity on the second line produces a 422 error under `lines.1.quantity`. On error, `check()->data()` omits the entire failing top-level field. Other valid top-level fields remain available. A plain `array` rule continues to return the original array and its contents.

Item rules accept a rule string, a list of rules or another shape. Field-level checks such as `required`, `nullable` and `max` run before descending into a collection. Object fields can use `same` and `confirmed` to compare siblings within that object. Custom callbacks receive the local object's fields or list elements and the local field name or string index. Invalid schema definitions raise `InvalidArgumentException` even for absent or empty collections.

Shapes validate PHP arrays. A list has contiguous integer keys starting at zero. An object has string keys. PHP represents both empty JSON collections as `[]`, so an empty array can satisfy either shape. JSON objects whose keys decode as consecutive integer indexes can also take the PHP list form. Apply `required` or a minimum count when an empty collection should fail.
