<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\HttpException;
use Core\RequestBody;

function bodyEnsure(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Request body assertion failed.');
    }
}

function bodyRejects(callable $operation, int $status = 400): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        bodyEnsure($exception->getStatus() === $status);
        return;
    }
    throw new RuntimeException('Expected request body rejection.');
}

$tests = [];
$tests['JSON preserves scalar types and large identifiers'] = static function (): void {
    $decoded = RequestBody::json('{"id":9223372036854775808,"note":"café","count":2,"price":12.5,"enabled":false,"empty":null}');
    bodyEnsure($decoded === ['id' => '9223372036854775808', 'note' => 'café', 'count' => 2, 'price' => 12.5, 'enabled' => false, 'empty' => null]);
};
$tests['empty objects and forms are accepted'] = static function (): void {
    bodyEnsure(RequestBody::json(' {} ') === []);
    bodyEnsure(RequestBody::form('') === []);
};
$tests['JSON strings with punctuation and escaped quotes are not mistaken for keys'] = static function (): void {
    $expected = ['note' => 'quoted "id": 2, } { \\ end', 'id' => 42];
    bodyEnsure(RequestBody::json(json_encode($expected, JSON_THROW_ON_ERROR)) === $expected);
};
foreach ([
    '{"id":42,"id":43}',
    '{"id":42,"id":42}',
    '{"id":42,"\\u0069d":43}',
    '{"_csrf_token":"first","_csrf_token":"last"}',
    '{"id":{"nested":1},"id":42}',
    '{"id":[1,2],"id":42}',
] as $position => $raw) {
    $tests['duplicate JSON keys ' . $position] = static fn() => bodyRejects(static fn() => RequestBody::json($raw));
}
foreach (['[]', 'null', 'true', '{bad}', '{"id":}', '{"note":[]}', '{"note":{}}', '{"count":1e309}', '{"owner.id":7}', '{"":1}', '{"0":1}'] as $position => $raw) {
    $tests['invalid JSON shape or fields ' . $position] = static fn() => bodyRejects(static fn() => RequestBody::json($raw));
}
$tests['JSON whitespace and escaped key names retain their values'] = static function (): void {
    bodyEnsure(RequestBody::json(" { \n\"\\u0069d\" : 42 , \"note\" : \"ok\" } \t") === ['id' => 42, 'note' => 'ok']);
};
$tests['forms decode UTF-8 plus signs and escaped separators'] = static function (): void {
    bodyEnsure(RequestBody::form('note=caf%C3%A9+%2B+milk%26sugar%3Dyes&id=42&empty') === ['note' => 'café + milk&sugar=yes', 'id' => '42', 'empty' => '']);
};
$tests['valid security field names remain available'] = static function (): void {
    bodyEnsure(RequestBody::form('_csrf_token=value&_integrity_token=other') === ['_csrf_token' => 'value', '_integrity_token' => 'other']);
};
foreach (['id=42&id=43', 'id=42&%69d=42', 'owner.id=7&owner_id=8', 'owner+id=7', 'note[]=x', 'note%5Bkey%5D=x', '=empty', '0=value', 'note=%', 'note=%2', 'note=%GG', 'note=%FF', 'na%00me=value'] as $position => $raw) {
    $tests['ambiguous or invalid form fields ' . $position] = static fn() => bodyRejects(static fn() => RequestBody::form($raw));
}
$tests['field count limit applies to form and JSON'] = static function (): void {
    $fields = [];
    for ($index = 0; $index < RequestBody::MAX_FIELDS + 1; $index++) {
        $fields['field_' . $index] = 'value';
    }
    bodyRejects(static fn() => RequestBody::form(http_build_query($fields)));
    bodyRejects(static fn() => RequestBody::json(json_encode($fields, JSON_THROW_ON_ERROR)));
    array_pop($fields);
    bodyEnsure(RequestBody::form(http_build_query($fields)) === $fields);
    bodyEnsure(RequestBody::json(json_encode($fields, JSON_THROW_ON_ERROR)) === $fields);
};
$tests['body size limit applies before decoding'] = static function (): void {
    $raw = str_repeat('a', RequestBody::MAX_BYTES + 1);
    bodyRejects(static fn() => RequestBody::form($raw), 413);
    bodyRejects(static fn() => RequestBody::json($raw), 413);
};
$tests['structured JSON is explicitly enabled and retains nested values'] = static function (): void {
    $values = ['payload' => ['items' => [['id' => 1, 'active' => true], ['id' => 2, 'active' => false]], 'empty' => null]];
    $raw = json_encode($values, JSON_THROW_ON_ERROR);
    bodyEnsure(RequestBody::json($raw, true) === $values);
    bodyRejects(static fn() => RequestBody::json($raw));
};
foreach (['{"payload":{"id":1,"id":2}}', '{"payload":[{"id":1,"\\u0069d":2}]}', '{"payload":{"value":1e309}}', '{"payload":{"items":[{"nested":true}],"items":[]}}'] as $position => $raw) {
    $tests['structured JSON still rejects ambiguous data ' . $position] = static fn() => bodyRejects(static fn() => RequestBody::json($raw, true));
}

$passed = 0;
$failures = [];
foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . get_class($exception);
    }
}
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . PHP_EOL);
}
echo $passed . ' request body tests passed; ' . count($failures) . ' failed.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
