<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\Application;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\Authenticate;
use Core\RequestBody;
use Core\Validation\Shape;
use Core\Validation\Validator;

function ensureNested(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Unexpected validation result.');
    }
}

$item = Shape::object([
    'sku' => 'required|string|max:10',
    'quantity' => 'required|integer|min:1',
    'tags' => Shape::listOf('required|string'),
]);
$rules = ['lines' => ['required', 'max:3', Shape::listOf($item)]];
$tests = [];

$tests['filters every declared object and retains types and order'] = static function () use ($rules): void {
    $input = [
        'lines' => [
            ['sku' => 'B', 'quantity' => '2', 'tags' => ['blue', 'red'], 'owner' => 88],
            ['sku' => 'A', 'quantity' => 1, 'unit_price' => 200],
        ],
        'role' => 'ignored',
    ];
    ensureNested(Validator::validate($input, $rules) === ['lines' => [
        ['sku' => 'B', 'quantity' => '2', 'tags' => ['blue', 'red']],
        ['sku' => 'A', 'quantity' => 1],
    ]]);
    ensureNested($input['lines'][0]['owner'] === 88);
};

$tests['collects indexed errors and withholds the entire failed branch'] = static function () use ($rules): void {
    $input = ['label' => 'kept', 'lines' => [
        ['sku' => 'A', 'quantity' => 1],
        ['quantity' => 0, 'tags' => ['valid', 42]],
        'unexpected',
    ]];
    $result = Validator::check($input, ['label' => 'required|string'] + $rules);
    ensureNested(!$result->valid() && $result->data() === ['label' => 'kept']);
    ensureNested(array_keys($result->errors()) === ['lines.1.sku', 'lines.1.quantity', 'lines.1.tags.1', 'lines.2']);
    ensureNested($result->errors()['lines.1.quantity'] === ['The lines.1.quantity field is below the allowed minimum.']);
};

$tests['rejects incorrect PHP collection shapes with one field error'] = static function (): void {
    foreach ([false, 'text', 1, (object) [], [1 => 'gap'], ['named' => 1]] as $value) {
        $result = Validator::check(['entry' => $value], ['entry' => Shape::listOf('string')]);
        ensureNested($result->data() === [] && array_keys($result->errors()) === ['entry']);
    }
    foreach ([false, null, 'text', (object) [], ['one'], [5 => 1], ['name' => 'value', 0 => 'mixed']] as $value) {
        $result = Validator::check(['entry' => $value], ['entry' => Shape::object([])]);
        ensureNested($result->data() === [] && array_keys($result->errors()) === ['entry']);
    }
};

$tests['validates and aggregates lists of primitive lists'] = static function (): void {
    $rules = ['matrix' => Shape::listOf(Shape::listOf('nullable|integer'))];
    $input = ['matrix' => [[0, '2'], [null], []]];
    ensureNested(Validator::validate($input, $rules) === $input);
    $result = Validator::check(['matrix' => [[true, 'invalid'], [null], [2.5]]], $rules);
    ensureNested(array_keys($result->errors()) === ['matrix.0.0', 'matrix.0.1', 'matrix.2.0']);
};

$tests['comparison and confirmation resolve against siblings in the same object'] = static function (): void {
    $rules = ['rows' => Shape::listOf(Shape::object(['pin' => 'required|confirmed', 'repeat' => 'required|same:pin']))];
    $input = ['pin' => 'wrong', 'rows' => [
        ['pin' => 'A', 'pin_confirmation' => 'A', 'repeat' => 'A'],
        ['pin' => 'B', 'pin_confirmation' => 'B', 'repeat' => 'B'],
    ]];
    ensureNested(Validator::validate($input, $rules) === ['rows' => [['pin' => 'A', 'repeat' => 'A'], ['pin' => 'B', 'repeat' => 'B']]]);
    $input['rows'][1]['pin_confirmation'] = 'A';
    $input['rows'][1]['repeat'] = 'A';
    ensureNested(array_keys(Validator::check($input, $rules)->errors()) === ['rows.1.pin', 'rows.1.repeat']);
};

$tests['custom rules see the local object or list and local field'] = static function (): void {
    $rules = ['entry' => Shape::object([
        'code' => [static fn($value, $input, $field) => $value === $input['other'] && $field === 'code'],
        'sequence' => Shape::listOf([static fn($value, $input, $field) => is_string($field) && $value === $input[$field]]),
    ])];
    $input = ['entry' => ['code' => 'A', 'other' => 'A', 'sequence' => [2, 4]]];
    ensureNested(Validator::validate($input, $rules) === ['entry' => ['code' => 'A', 'sequence' => [2, 4]]]);
};

$tests['respects optional nullable required and empty collections'] = static function (): void {
    $object = Shape::object(['name' => 'required|string']);
    ensureNested(Validator::validate([], ['entry' => ['sometimes', 'required', $object]]) === []);
    ensureNested(Validator::validate(['entry' => null], ['entry' => ['nullable', $object]]) === ['entry' => null]);
    ensureNested(!Validator::check(['entry' => null], ['entry' => ['required', 'nullable', $object]])->valid());
    ensureNested(array_keys(Validator::check(['entry' => []], ['entry' => $object])->errors()) === ['entry.name']);
    ensureNested(Validator::validate(['entry' => ['unknown' => 1]], ['entry' => Shape::object([])]) === ['entry' => []]);
    ensureNested(Validator::validate(['entry' => []], ['entry' => Shape::listOf($object)]) === ['entry' => []]);
};

$tests['collection size constraints precede item callbacks'] = static function (): void {
    $calls = 0;
    $schema = ['rows' => [Shape::listOf([static function () use (&$calls) {
        $calls++;
        return true;
    }]), 'max:1']];
    $result = Validator::check(['rows' => [1, 2]], $schema);
    ensureNested($calls === 0 && array_keys($result->errors()) === ['rows']);
};

$tests['reports invalid definitions even when containers are absent or empty'] = static function (): void {
    foreach ([
        [Shape::object(['bad.field' => 'string'])],
        [Shape::listOf(Shape::object(['value' => 'same:invalid.name']))],
        [Shape::listOf(['min:broken'])],
        [Shape::object([]), Shape::object([])],
        [Shape::listOf('string'), Shape::object([])],
    ] as $definition) {
        foreach ([[], ['entry' => []], ['entry' => null]] as $input) {
            try {
                Validator::check($input, ['entry' => $definition]);
            } catch (InvalidArgumentException) {
                continue;
            }
            throw new RuntimeException('Invalid shape definition accepted.');
        }
    }
};

$tests['request validation gives clients structured 422 errors and safe writes'] = static function () use ($rules): void {
    $app = Application::create(dirname(__DIR__));
    $writes = [];
    $app->router()->post('/lines', static function (Request $request) use ($rules, &$writes): Response {
        $data = $request->validate($rules);
        $writes[] = $data;
        return Response::json($data, 201);
    })->middleware(new Authenticate(static fn() => 'fixture-user'))->stateless()->policy(['editable' => ['lines'], 'protected' => []]);

    $send = static fn(string $json): Response => $app->handle(new Request('POST', '/lines', ['Content-Type' => 'application/json'], [], RequestBody::json($json, true)));
    $failure = $send('{"lines":[{"quantity":1,"sku":"A"},{"quantity":"invalid-private","sku":"B"}]}');
    ensureNested($failure->status() === 422 && $writes === []);
    $payload = json_decode($failure->body(), true, flags: JSON_THROW_ON_ERROR);
    ensureNested(array_keys($payload['errors']) === ['lines.1.quantity'] && !str_contains($failure->body(), 'invalid-private'));
    $success = $send('{"lines":[{"sku":"A","quantity":"1","price":"private"}]}');
    ensureNested($success->status() === 201 && $writes === [['lines' => [['sku' => 'A', 'quantity' => '1']]]]);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
}
echo (count($tests) - $failures) . '/' . count($tests) . ' nested validation tests passed.' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
