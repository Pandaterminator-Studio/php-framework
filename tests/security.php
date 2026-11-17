<?php

namespace {
    spl_autoload_register(static function (string $class): void {
        $path = dirname(__DIR__) . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    ob_start();
    $sessionDirectory = sys_get_temp_dir() . '/iris-security-tests-' . bin2hex(random_bytes(8));
    if (!mkdir($sessionDirectory, 0700)) {
        throw new \RuntimeException('Cannot create isolated test session directory.');
    }
    ini_set('session.save_path', $sessionDirectory);
    ini_set('session.save_handler', 'files');
    \Core\Session::start();
}

namespace App\Controllers {
    final class IntegrityFixture extends \Core\Controller
    {
        public static array $events = [];
        public static array $received = [];
        public static bool $deny = false;

        protected function requestPolicies(): array
        {
            return [
                'save' => ['methods' => ['POST', 'PUT', 'PATCH', 'DELETE'], 'editable' => ['note'], 'protected' => ['id', 'price', 'owner_id', 'role']],
                'review' => ['methods' => ['GET'], 'editable' => [], 'protected' => ['id', 'price', 'owner_id', 'role'], 'integrity' => true],
            ];
        }

        protected function before(): bool
        {
            self::$events[] = 'before';
            return !self::$deny;
        }

        protected function after(): void
        {
            self::$events[] = 'after';
        }

        public function saveAction(): void
        {
            self::$events[] = 'save';
            self::$received = $this->input();
        }

        public function indexAction(): void
        {
            self::$events[] = 'index';
        }

        public function reviewAction(): void
        {
            self::$events[] = 'review';
            self::$received = $this->input();
        }

        protected function hiddenAction(): void
        {
            self::$events[] = 'hidden';
        }

        public static function staticAction(): void
        {
            self::$events[] = 'static';
        }
    }
}

namespace {
    use App\Controllers\IntegrityFixture;
    use Core\DataIntegrity;
    use Core\HttpException;
    use Core\RequestGuard;
    use Core\Router;
    use Core\Security;
    use Core\Session;

    $passed = 0;
    $failures = [];
    $tests = [];

    function ensure(bool $condition, string $message = 'Assertion failed.'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function expectHttp(callable $operation, int $status): void
    {
        try {
            $operation();
        } catch (HttpException $exception) {
            ensure($exception->getStatus() === $status, 'Unexpected HTTP error status.');
            return;
        }
        throw new \RuntimeException('Expected HTTP rejection was not raised.');
    }

    function expectFailure(callable $operation, string $type): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            ensure($exception instanceof $type, 'Unexpected exception type.');
            return;
        }
        throw new \RuntimeException('Expected exception was not raised.');
    }

    function trustedValues(): array
    {
        return ['id' => 41, 'price' => '19.95', 'owner_id' => 7, 'role' => 'member'];
    }

    function challenge(string $action = 'save', string $method = 'POST', array $route = ['id' => '41']): string
    {
        return DataIntegrity::issue(IntegrityFixture::class . ':' . $action, trustedValues(), $method, $route);
    }

    function formBody(string $token): array
    {
        return [
            '_csrf_token' => Security::generateCSRFToken(),
            '_integrity_token' => $token,
            'id' => '41',
            'price' => '19.95',
            'owner_id' => '7',
            'role' => 'member',
            'note' => 'Updated note',
        ];
    }

    function dispatch(string $path, RequestGuard $guard): void
    {
        $router = new Router();
        $router->add('{controller}/{action}');
        $router->add('{controller}/{action}/{id:\d+}');
        $router->dispatch($path, $guard);
    }

    $tests['valid form data reaches action with server value types'] = static function (): void {
        dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], formBody(challenge())));
        ensure(IntegrityFixture::$events === ['before', 'save', 'after']);
        ensure(IntegrityFixture::$received === array_merge(['note' => 'Updated note'], trustedValues()));
    };

    foreach (['id', 'price', 'owner_id', 'role'] as $field) {
        $tests['changed protected ' . $field . ' is rejected'] = static function () use ($field): void {
            $body = formBody(challenge());
            $body[$field] = 'altered';
            expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
            ensure(IntegrityFixture::$events === []);
        };
        $tests['removed protected ' . $field . ' is rejected'] = static function () use ($field): void {
            $body = formBody(challenge());
            unset($body[$field]);
            expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
        };
        $tests['array protected ' . $field . ' is rejected'] = static function () use ($field): void {
            $body = formBody(challenge());
            $body[$field] = ['unchanged', 'altered'];
            expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 400);
        };
    }

    $tests['extra fields cannot be mass assigned'] = static function (): void {
        $body = formBody(challenge());
        $body['is_admin'] = '1';
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 400);
    };
    $tests['nested editable input is rejected'] = static function (): void {
        $body = formBody(challenge());
        $body['note'] = ['role' => 'admin'];
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 400);
    };
    $tests['protected null values cannot bypass comparison'] = static function (): void {
        $body = formBody(challenge());
        $body['id'] = null;
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
    };
    $tests['missing integrity token is rejected'] = static function (): void {
        $body = formBody(challenge());
        unset($body['_integrity_token']);
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
    };
    $tests['altered integrity token is rejected'] = static function (): void {
        $body = formBody(challenge());
        $body['_integrity_token'][0] = $body['_integrity_token'][0] === 'a' ? 'b' : 'a';
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
    };
    $tests['array integrity token is rejected'] = static function (): void {
        $body = formBody(challenge());
        $body['_integrity_token'] = [$body['_integrity_token']];
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
    };
    $tests['expired challenge is rejected'] = static function (): void {
        $token = challenge();
        $body = formBody($token);
        $_SESSION['_iris_integrity'][$token]['expires'] = time() - 1;
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
    };
    $tests['successful challenge cannot be replayed'] = static function (): void {
        $body = formBody(challenge());
        dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body));
        IntegrityFixture::$events = [];
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
        ensure(IntegrityFixture::$events === []);
    };
    $tests['challenge cannot be moved to another method'] = static function (): void {
        $body = formBody(challenge());
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('PATCH', [], $body)), 403);
    };
    $tests['challenge cannot be moved to another route record'] = static function (): void {
        $body = formBody(challenge());
        expectHttp(static fn() => dispatch('integrity-fixture/save/42', new RequestGuard('POST', [], $body)), 403);
    };
    $tests['challenge cannot be moved to another action'] = static function (): void {
        $body = formBody(challenge('different'));
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
    };
    $tests['challenge cannot be used in another session'] = static function (): void {
        $body = formBody(challenge());
        $_SESSION = [];
        $body['_csrf_token'] = Security::generateCSRFToken();
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
    };
    foreach (['missing', 'array', 'invalid'] as $kind) {
        $tests[$kind . ' csrf token is rejected'] = static function () use ($kind): void {
            $body = formBody(challenge());
            if ($kind === 'missing') {
                unset($body['_csrf_token']);
            } elseif ($kind === 'array') {
                $body['_csrf_token'] = [$body['_csrf_token']];
            } else {
                $body['_csrf_token'] = str_repeat('0', 64);
            }
            expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
        };
    }
    $tests['conflicting header and body csrf tokens are rejected'] = static function (): void {
        $body = formBody(challenge());
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body, ['X-CSRF-Token' => str_repeat('0', 64)])), 400);
    };
    $tests['conflicting header and body integrity tokens are rejected'] = static function (): void {
        $body = formBody(challenge());
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body, ['X-Integrity-Token' => str_repeat('0', 64)])), 400);
    };
    $tests['tokens can be supplied through headers'] = static function (): void {
        $body = formBody(challenge());
        $headers = ['X-CSRF-Token' => $body['_csrf_token'], 'X-Integrity-Token' => $body['_integrity_token']];
        unset($body['_csrf_token'], $body['_integrity_token']);
        dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body, $headers));
        ensure(IntegrityFixture::$events === ['before', 'save', 'after']);
    };
    $tests['write query parameters cannot override body fields'] = static function (): void {
        $body = formBody(challenge());
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', ['id' => '42'], $body)), 400);
    };
    $tests['unconfigured writes are denied'] = static function (): void {
        expectHttp(static fn() => dispatch('integrity-fixture/index', new RequestGuard('POST')), 405);
        ensure(IntegrityFixture::$events === []);
    };
    $tests['wrong method is denied before action'] = static function (): void {
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('GET')), 405);
        ensure(IntegrityFixture::$events === []);
    };
    $tests['ordinary reads still run hooks'] = static function (): void {
        dispatch('integrity-fixture/index', new RequestGuard('GET'));
        ensure(IntegrityFixture::$events === ['before', 'index', 'after']);
    };
    $tests['before can deny action execution'] = static function (): void {
        IntegrityFixture::$deny = true;
        dispatch('integrity-fixture/index', new RequestGuard('GET'));
        ensure(IntegrityFixture::$events === ['before']);
    };
    foreach (['index-action', 'execute-action', 'hidden', 'static', 'missing'] as $action) {
        $tests['unexposed action ' . $action . ' is denied'] = static function () use ($action): void {
            expectHttp(static fn() => dispatch('integrity-fixture/' . $action, new RequestGuard('GET')), 404);
            ensure(IntegrityFixture::$events === []);
        };
    }
    $tests['protected reads require an integrity challenge'] = static function (): void {
        expectHttp(static fn() => dispatch('integrity-fixture/review/41', new RequestGuard('GET')), 403);
        $query = trustedValues();
        $query['_integrity_token'] = challenge('review', 'GET');
        dispatch('integrity-fixture/review/41', new RequestGuard('GET', $query));
        ensure(IntegrityFixture::$received === trustedValues());
    };
    $tests['pending challenge count is bounded'] = static function (): void {
        for ($number = 0; $number < 80; $number++) {
            challenge();
        }
        ensure(count($_SESSION['_iris_integrity']) === 64);
    };
    $tests['invalid challenge configuration fails closed'] = static function (): void {
        expectFailure(static fn() => DataIntegrity::issue('example', ['price' => 19.95]), \InvalidArgumentException::class);
        expectFailure(static fn() => DataIntegrity::issue('example', ['id' => 41], 'POST', ['id' => 42]), \InvalidArgumentException::class);
        expectFailure(static fn() => DataIntegrity::issue('example', [], 'POST', [], 0), \InvalidArgumentException::class);
    };
    $tests['protected fields cannot also be editable'] = static function (): void {
        $token = challenge();
        expectFailure(static fn() => DataIntegrity::verify($token, IntegrityFixture::class . ':save', 'POST', ['id' => '41'], trustedValues(), ['id']), \InvalidArgumentException::class);
    };
    $tests['challenge missing declared protected fields is rejected'] = static function (): void {
        $token = DataIntegrity::issue(IntegrityFixture::class . ':save', ['id' => 41], 'POST', ['id' => '41']);
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], formBody($token))), 403);
    };
    $tests['challenge with additional protected fields is rejected'] = static function (): void {
        $token = DataIntegrity::issue(IntegrityFixture::class . ':save', array_merge(trustedValues(), ['tenant_id' => 9]), 'POST', ['id' => '41']);
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], formBody($token))), 403);
    };
    $tests['write policy must explicitly declare protected fields'] = static function (): void {
        $guard = new RequestGuard('POST', [], formBody(challenge()));
        expectFailure(static fn() => $guard->validate(IntegrityFixture::class . ':save', ['id' => '41'], ['methods' => ['POST'], 'editable' => ['note']]), \InvalidArgumentException::class);
    };
    $tests['read requests cannot smuggle form data'] = static function (): void {
        expectHttp(static fn() => dispatch('integrity-fixture/index', new RequestGuard('GET', [], ['role' => 'admin'])), 400);
    };
    $tests['floating point protected values are rejected'] = static function (): void {
        $body = formBody(challenge());
        $body['id'] = 41.0;
        expectHttp(static fn() => dispatch('integrity-fixture/save/41', new RequestGuard('POST', [], $body)), 403);
    };
    $tests['unchanged current database values are accepted'] = static function (): void {
        DataIntegrity::assertCurrent(trustedValues(), ['role' => 'member', 'owner_id' => '7', 'price' => '19.95', 'id' => '41', 'note' => 'Unrelated'], array_keys(trustedValues()));
    };
    foreach (['id', 'price', 'owner_id', 'role'] as $field) {
        $tests['changed current database ' . $field . ' produces conflict'] = static function () use ($field): void {
            $current = trustedValues();
            $current[$field] = 'changed';
            expectHttp(static fn() => DataIntegrity::assertCurrent(trustedValues(), $current, array_keys(trustedValues())), 409);
        };
    }
    $tests['missing current database field produces conflict'] = static function (): void {
        $current = trustedValues();
        unset($current['owner_id']);
        expectHttp(static fn() => DataIntegrity::assertCurrent(trustedValues(), $current, array_keys(trustedValues())), 409);
    };
    $tests['missing verified field produces conflict'] = static function (): void {
        $verified = trustedValues();
        unset($verified['owner_id']);
        expectHttp(static fn() => DataIntegrity::assertCurrent($verified, trustedValues(), array_keys(trustedValues())), 409);
    };
    $tests['csrf helper safely handles absent and malformed state'] = static function (): void {
        unset($_SESSION['csrf_token']);
        ensure(!Security::validateCSRFToken(null));
        ensure(!Security::validateCSRFToken([]));
        $_SESSION['csrf_token'] = ['bad'];
        ensure(!Security::validateCSRFToken(str_repeat('0', 64)));
        ensure(Security::validateCSRFToken(Security::generateCSRFToken()));
    };
    $tests['method override is rejected in global requests'] = static function (): void {
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $previousPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET = [];
            $_POST = ['_method' => 'DELETE'];
            expectHttp(static fn() => RequestGuard::fromGlobals(), 400);
            $_POST = [];
            $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'DELETE';
            expectHttp(static fn() => RequestGuard::fromGlobals(), 400);
        } finally {
            $_SERVER = $previousServer;
            $_GET = $previousGet;
            $_POST = $previousPost;
        }
    };
    $tests['session mutation and listing use actual stored state'] = static function (): void {
        ensure(Session::Add('test_value', 'first'));
        ensure(!Session::Add('test_value', 'duplicate'));
        ensure(Session::Update('test_value', 'second'));
        ensure(Session::List()['test_value'] === 'second');
        ensure(Session::Remove('test_value'));
        ensure(!Session::Check('test_value'));
        ensure(!Session::Update('test_value', 'missing'));
        ensure(!Session::Remove('test_value'));
    };
    $tests['session regeneration invalidates pending security tokens'] = static function (): void {
        $previousId = session_id();
        challenge();
        Security::generateCSRFToken();
        $_SESSION['test_value'] = 'retained';
        Session::regenerate();
        ensure(session_id() !== $previousId);
        ensure(!isset($_SESSION['csrf_token']) && !isset($_SESSION['_iris_integrity']));
        ensure($_SESSION['test_value'] === 'retained');
    };
    $tests['session cookies are strict and HTTP only'] = static function (): void {
        $parameters = session_get_cookie_params();
        ensure($parameters['httponly'] === true);
        ensure($parameters['samesite'] === 'Lax');
        ensure(ini_get('session.use_strict_mode') === '1');
        ensure(ini_get('session.use_only_cookies') === '1');
    };
    $tests['authenticated encryption round trips including empty data'] = static function (): void {
        $key = random_bytes(32);
        foreach (['synthetic sample', ''] as $plaintext) {
            $encrypted = Security::encryptData($plaintext, $key);
            ensure(Security::decryptData($encrypted, $key) === $plaintext);
        }
        ensure(Security::encryptData('same', $key) !== Security::encryptData('same', $key));
    };
    foreach (['nonce', 'tag', 'ciphertext'] as $part) {
        $tests['modified encrypted ' . $part . ' fails authentication'] = static function () use ($part): void {
            $key = random_bytes(32);
            $encrypted = Security::encryptData('synthetic sample', $key);
            $bytes = base64_decode(substr($encrypted, 8), true);
            $position = ['nonce' => 0, 'tag' => 12, 'ciphertext' => 28][$part];
            $bytes[$position] = chr(ord($bytes[$position]) ^ 1);
            expectFailure(static fn() => Security::decryptData('iris:v1:' . base64_encode($bytes), $key), \UnexpectedValueException::class);
        };
    }
    $tests['wrong encryption key is rejected'] = static function (): void {
        $encrypted = Security::encryptData('synthetic sample', random_bytes(32));
        expectFailure(static fn() => Security::decryptData($encrypted, random_bytes(32)), \UnexpectedValueException::class);
    };
    $tests['malformed and legacy encryption are rejected'] = static function (): void {
        $key = random_bytes(32);
        foreach (['legacy-cbc-data', 'iris:v1:***', 'iris:v1:' . base64_encode('short')] as $ciphertext) {
            expectFailure(static fn() => Security::decryptData($ciphertext, $key), \UnexpectedValueException::class);
        }
        expectFailure(static fn() => Security::encryptData('sample', 'short'), \InvalidArgumentException::class);
    };
    $tests['explicit HTTP exceptions keep status without exposing executable HTML'] = static function (): void {
        ob_start();
        \Core\Error::exceptionHandler(new HttpException(403, '<script>example</script>'));
        $output = ob_get_clean();
        ensure(http_response_code() === 403);
        ensure(!str_contains($output, '<script>'));
        ensure(!str_contains($output, __FILE__));
    };

    foreach ($tests as $name => $operation) {
        $_SESSION = [];
        IntegrityFixture::$events = [];
        IntegrityFixture::$received = [];
        IntegrityFixture::$deny = false;
        try {
            $operation();
            $passed++;
        } catch (\Throwable $exception) {
            $failures[] = $name . ': ' . get_class($exception) . ' (' . $exception->getMessage() . ')';
        }
    }

    try {
        $_SESSION['test_value'] = 'removed';
        Session::Destroy();
        ensure($_SESSION === [] && session_status() !== PHP_SESSION_ACTIVE && session_id() === '');
        $passed++;
    } catch (\Throwable $exception) {
        $failures[] = 'session destruction clears state: ' . get_class($exception);
    }
    foreach (glob($sessionDirectory . '/sess_*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    rmdir($sessionDirectory);
    ob_end_clean();

    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    fwrite(STDOUT, $passed . ' security tests passed; ' . count($failures) . ' failed.' . PHP_EOL);
    exit($failures === [] ? 0 : 1);
}
