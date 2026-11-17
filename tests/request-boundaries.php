<?php

require dirname(__DIR__) . '/.iris/vendor/autoload.php';

use Core\DataIntegrity;
use Core\HttpException;
use Core\RequestGuard;
use Core\Session;

error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): void {
    if ((error_reporting() & $level) !== 0) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
});
ob_start();
$directory = sys_get_temp_dir() . '/iris-request-boundaries-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Could not create an isolated session directory.');
}
ini_set('session.save_path', $directory);
Session::start();

function assertBoundary(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Boundary assertion failed.');
    }
}

function expectBoundaryFailure(callable $operation, string $type, ?int $status = null): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        assertBoundary($exception instanceof $type);
        if ($status !== null) {
            assertBoundary($exception instanceof HttpException && $exception->getStatus() === $status);
        }
        return;
    }
    throw new RuntimeException('Expected boundary rejection was not raised.');
}

$tests = [];
$invalidPolicies = [
    'unknown policy key' => ['protectd' => ['id']],
    'numeric policy key' => [0 => ['id']],
    'methods explicit null' => ['methods' => null],
    'methods scalar' => ['methods' => 'GET'],
    'methods empty' => ['methods' => []],
    'methods associative' => ['methods' => ['read' => 'GET']],
    'methods duplicate' => ['methods' => ['GET', 'GET']],
    'methods lowercase' => ['methods' => ['get']],
    'methods unsupported' => ['methods' => ['TRACE']],
    'methods nested' => ['methods' => [['GET']]],
    'methods boolean' => ['methods' => [true]],
    'editable explicit null' => ['editable' => null],
    'editable scalar' => ['editable' => 'note'],
    'editable associative' => ['editable' => ['name' => 'note']],
    'editable duplicate' => ['editable' => ['note', 'note']],
    'editable invalid field name' => ['editable' => ['owner.id']],
    'editable reserved field name' => ['editable' => ['_csrf_token']],
    'editable nested' => ['editable' => [['note']]],
    'protected explicit null' => ['protected' => null],
    'protected scalar' => ['protected' => 'id'],
    'protected associative' => ['protected' => ['name' => 'id']],
    'protected duplicate' => ['protected' => ['id', 'id']],
    'protected invalid field name' => ['protected' => ['owner id']],
    'protected nested' => ['protected' => [['id']]],
    'overlapping editable and protected fields' => ['editable' => ['id'], 'protected' => ['id']],
    'integrity explicit null' => ['integrity' => null],
    'integrity string' => ['integrity' => 'true'],
    'integrity integer' => ['integrity' => 1],
    'integrity array' => ['integrity' => []],
];
foreach ($invalidPolicies as $name => $policy) {
    $tests[$name . ' fails closed'] = static function () use ($policy): void {
        $guard = new RequestGuard('GET');
        expectBoundaryFailure(static fn() => $guard->validate('boundary:read', [], $policy), InvalidArgumentException::class);
    };
}

$tests['default policy keeps ordinary GET and HEAD working'] = static function (): void {
    foreach (['GET', 'HEAD'] as $method) {
        $guard = new RequestGuard($method, ['page' => '2']);
        assertBoundary($guard->validate('boundary:read', [], []) === ['page' => '2']);
    }
};
$tests['supported uppercase method policy is accepted'] = static function (): void {
    $guard = new RequestGuard('OPTIONS');
    assertBoundary($guard->validate('boundary:read', [], ['methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], 'editable' => [], 'protected' => [], 'integrity' => false]) === []);
};
foreach (['GET', 'HEAD'] as $method) {
    foreach ([null, false] as $integrity) {
        $label = $integrity === null ? 'omitted flag' : 'false flag';
        $tests[$method . ' protected fields with ' . $label . ' require token'] = static function () use ($method, $integrity): void {
            $policy = ['methods' => [$method], 'protected' => ['id']];
            if ($integrity !== null) {
                $policy['integrity'] = $integrity;
            }
            $guard = new RequestGuard($method, ['id' => '42']);
            expectBoundaryFailure(static fn() => $guard->validate('boundary:read', [], $policy), HttpException::class, 403);
        };
        $tests[$method . ' protected fields with ' . $label . ' accept bound token'] = static function () use ($method, $integrity): void {
            $token = DataIntegrity::issue('boundary:read', ['id' => 42], $method);
            $policy = ['methods' => [$method], 'protected' => ['id']];
            if ($integrity !== null) {
                $policy['integrity'] = $integrity;
            }
            $guard = new RequestGuard($method, ['id' => '42'], [], ['X-Integrity-Token' => $token]);
            assertBoundary($guard->validate('boundary:read', [], $policy) === ['id' => 42]);
        };
    }
}
$tests['empty protected read policy does not need token'] = static function (): void {
    $guard = new RequestGuard('GET', ['page' => '2']);
    assertBoundary($guard->validate('boundary:read', [], ['protected' => [], 'editable' => [], 'integrity' => false]) === ['page' => '2']);
};
$tests['explicit integrity read policy still needs declared protected list'] = static function (): void {
    $guard = new RequestGuard('GET');
    expectBoundaryFailure(static fn() => $guard->validate('boundary:read', [], ['integrity' => true]), InvalidArgumentException::class);
};
foreach (['X-CSRF-Token', 'X-Integrity-Token'] as $header) {
    foreach (['same', 'different'] as $value) {
        $tests[$header . ' case-folded duplicate with ' . $value . ' values is rejected'] = static function () use ($header, $value): void {
            expectBoundaryFailure(static fn() => new RequestGuard('GET', [], [], [$header => 'first', strtolower($header) => $value === 'same' ? 'first' : 'second']), HttpException::class, 400);
        };
    }
}
$tests['case-insensitive duplicate null headers are rejected'] = static function (): void {
    expectBoundaryFailure(static fn() => new RequestGuard('GET', [], [], ['X-CSRF-Token' => null, 'x-csrf-token' => null]), HttpException::class, 400);
};
$tests['single mixed-case security header remains valid'] = static function (): void {
    $token = DataIntegrity::issue('boundary:read', ['id' => 42], 'GET');
    $guard = new RequestGuard('GET', ['id' => '42'], [], ['x-InTeGrItY-tOkEn' => $token, 'x-csrf-token' => null]);
    assertBoundary($guard->validate('boundary:read', [], ['protected' => ['id']]) === ['id' => 42]);
};
foreach ([['X-CSRF-Token' => ['nested']], ['X-CSRF-Token' => true], ['X-CSRF-Token' => "value\r\ninjected: header"], ['X-CSRF\nToken' => 'value'], ['X CSRF Token' => 'value'], ['X:CSRF' => 'value'], ['' => 'value']] as $index => $headers) {
    $tests['malformed header map ' . $index . ' is rejected'] = static function () use ($headers): void {
        expectBoundaryFailure(static fn() => new RequestGuard('GET', [], [], $headers), HttpException::class, 400);
    };
}

$passed = 0;
$failures = [];
try {
    foreach ($tests as $name => $operation) {
        $_SESSION = [];
        try {
            $operation();
            $passed++;
        } catch (Throwable $exception) {
            $failures[] = $name . ': ' . get_class($exception) . ' (' . $exception->getMessage() . ')';
        }
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        Session::Destroy();
    }
    foreach (glob($directory . '/sess_*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
    ob_end_clean();
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
}
fwrite(STDOUT, $passed . ' request boundary tests passed; ' . count($failures) . ' failed.' . PHP_EOL);
exit($failures === [] ? 0 : 1);
