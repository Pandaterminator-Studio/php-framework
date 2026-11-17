<?php

namespace {
    require dirname(__DIR__) . '/.iris/vendor/autoload.php';
}

namespace IrisFoundationFixtures {
    interface Clock
    {
    }

    interface MissingService
    {
    }

    final class TestClock implements Clock
    {
    }

    final class Consumer
    {
        public function __construct(public Clock $clock)
        {
        }
    }

    final class Defaults
    {
        public function __construct(public string $label = 'default', public int $count = 3)
        {
        }
    }

    final class RequiredScalar
    {
        public function __construct(public string $label)
        {
        }
    }

    final class NullableDependency
    {
        public function __construct(public ?MissingService $service)
        {
        }
    }

    final class CircularFirst
    {
        public function __construct(CircularSecond $second)
        {
        }
    }

    final class CircularSecond
    {
        public function __construct(CircularFirst $first)
        {
        }
    }

    abstract class AbstractService
    {
    }

    final class VariadicService
    {
        public array $names;

        public function __construct(string ...$names)
        {
            $this->names = $names;
        }
    }

    final class AmbiguousDependency
    {
        public function __construct(public Clock|\DateTimeImmutable $clock)
        {
        }
    }

    function createClock(\Core\Container $container): TestClock
    {
        return new TestClock();
    }
}

namespace {
    use Core\Config;
    use Core\Container;
    use Core\Container\ContainerException;
    use Core\Container\NotFoundException;
    use Core\Validation\ValidationException;
    use Core\Validation\Validator;
    use IrisFoundationFixtures\AbstractService;
    use IrisFoundationFixtures\AmbiguousDependency;
    use IrisFoundationFixtures\CircularFirst;
    use IrisFoundationFixtures\Clock;
    use IrisFoundationFixtures\Consumer;
    use IrisFoundationFixtures\Defaults;
    use IrisFoundationFixtures\NullableDependency;
    use IrisFoundationFixtures\RequiredScalar;
    use IrisFoundationFixtures\TestClock;
    use IrisFoundationFixtures\VariadicService;

    error_reporting(E_ALL);
    set_error_handler(static function (int $level, string $message, string $file, int $line): void {
        if ((error_reporting() & $level) !== 0) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }
    });

    function foundationEnsure(bool $condition): void
    {
        if (!$condition) {
            throw new \RuntimeException('Foundation assertion failed.');
        }
    }

    function foundationRejects(callable $operation, string $type): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            foundationEnsure($exception instanceof $type);
            return;
        }
        throw new \RuntimeException('Expected foundation rejection was not raised.');
    }

    $tests = [];
    $tests['container implements PSR interfaces and autowires classes'] = static function (): void {
        $container = new Container();
        foundationEnsure($container instanceof \Psr\Container\ContainerInterface);
        foundationEnsure($container->has(TestClock::class));
        foundationEnsure(!$container->has(Clock::class));
        $container->bind(Clock::class, TestClock::class);
        foundationEnsure($container->get(Consumer::class)->clock instanceof TestClock);
        foundationEnsure($container->get(TestClock::class) !== $container->get(TestClock::class));
    };
    $tests['shared services resolve once and can be overridden'] = static function (): void {
        $container = new Container();
        $count = 0;
        $container->singleton(Clock::class, static function () use (&$count): TestClock {
            $count++;
            return new TestClock();
        });
        $first = $container->get(Clock::class);
        foundationEnsure($container->get(Clock::class) === $first && $count === 1);
        $override = new TestClock();
        $container->instance(Clock::class, $override);
        foundationEnsure($container->get(Consumer::class)->clock === $override);
        $container->bind(Clock::class, TestClock::class);
        foundationEnsure($container->get(Clock::class) !== $override);
        $container->singleton(TestClock::class, TestClock::class);
        foundationEnsure($container->get(TestClock::class) === $container->get(TestClock::class));
    };
    $tests['container honors defaults and named constructor parameters'] = static function (): void {
        $container = new Container();
        foundationEnsure($container->make(Defaults::class)->label === 'default');
        $value = $container->make(Defaults::class, ['count' => 7, 'label' => 'custom']);
        foundationEnsure($value->count === 7 && $value->label === 'custom');
        foundationEnsure($container->make(RequiredScalar::class, ['label' => 'provided'])->label === 'provided');
        foundationEnsure($container->make(NullableDependency::class)->service === null);
        foundationRejects(static fn() => $container->make(RequiredScalar::class), ContainerException::class);
        foundationRejects(static fn() => $container->make(Defaults::class, ['typo' => 1]), ContainerException::class);
        foundationRejects(static fn() => $container->make(Defaults::class, [1]), ContainerException::class);
    };
    $tests['make constructs fresh classes without reusing their shared instance'] = static function (): void {
        $container = new Container();
        $container->instance(TestClock::class, new TestClock());
        foundationEnsure($container->make(TestClock::class) !== $container->get(TestClock::class));
    };
    $tests['factories support closures named callables and aliases'] = static function (): void {
        $container = new Container();
        $clock = new TestClock();
        $container->instance(Clock::class, $clock);
        $container->bind('alias', Clock::class);
        foundationEnsure($container->get('alias') === $clock);
        $container->bind('factory', static fn(Container $services): Consumer => new Consumer($services->get(Clock::class)));
        foundationEnsure($container->get('factory')->clock === $clock);
        $container->bind('named', 'IrisFoundationFixtures\\createClock');
        foundationEnsure($container->get('named') instanceof TestClock);
    };
    $tests['container errors implement PSR exception contracts'] = static function (): void {
        $container = new Container();
        foundationEnsure(!$container->has('missing') && !$container->has(AbstractService::class));
        foundationRejects(static fn() => $container->get('missing'), \Psr\Container\NotFoundExceptionInterface::class);
        foundationRejects(static fn() => $container->get(AbstractService::class), \Psr\Container\NotFoundExceptionInterface::class);
        foundationRejects(static fn() => $container->make(AbstractService::class), \Psr\Container\ContainerExceptionInterface::class);
        foreach (['', ' padded ', "bad\0id"] as $id) {
            foundationEnsure(!$container->has($id));
            foundationRejects(static fn() => $container->get($id), NotFoundException::class);
        }
        $container->bind('invalid-result', static fn() => 42);
        foundationRejects(static fn() => $container->get('invalid-result'), ContainerException::class);
    };
    $tests['circular failures do not poison later resolutions'] = static function (): void {
        $container = new Container();
        foundationRejects(static fn() => $container->get(CircularFirst::class), ContainerException::class);
        $container->bind('first-alias', 'second-alias');
        $container->bind('second-alias', 'first-alias');
        foundationRejects(static fn() => $container->get('first-alias'), ContainerException::class);
        foundationEnsure($container->get(TestClock::class) instanceof TestClock);
    };
    $tests['variadic and ambiguous constructors need explicit usable overrides'] = static function (): void {
        $container = new Container();
        foundationEnsure($container->make(VariadicService::class, ['names' => ['one', 'two']])->names === ['one', 'two']);
        foundationRejects(static fn() => $container->make(VariadicService::class, ['names' => null]), ContainerException::class);
        foundationRejects(static fn() => $container->make(AmbiguousDependency::class), ContainerException::class);
        $clock = new TestClock();
        foundationEnsure($container->make(AmbiguousDependency::class, ['clock' => $clock])->clock === $clock);
    };

    $tests['configuration preserves null values and resolves nested keys'] = static function (): void {
        $config = new Config(['app' => ['debug' => false, 'optional' => null], 'list' => ['one']]);
        foundationEnsure($config->get('missing', 'fallback') === 'fallback');
        foundationEnsure($config->has('app.optional') && $config->get('app.optional', 'fallback') === null);
        foundationEnsure($config->get('list.0') === 'one');
        $config->set('app.name', 'Iris');
        foundationEnsure($config->get('app.name') === 'Iris' && $config->get('app.debug') === false);
        foundationRejects(static fn() => $config->set('app.name.child', 'invalid'), \InvalidArgumentException::class);
        foreach (['', '.app', 'app.', 'app..name', '../name'] as $key) {
            foundationRejects(static fn() => $config->get($key), \InvalidArgumentException::class);
        }
    };
    $tests['configuration merging replaces security lists and clears explicit arrays'] = static function (): void {
        $config = new Config(['app' => ['name' => 'Iris', 'debug' => false], 'allow' => ['old', 'other'], 'clear' => ['entry' => 1]]);
        $config->merge(['app' => ['debug' => true], 'allow' => ['new'], 'clear' => []]);
        foundationEnsure($config->get('app.name') === 'Iris' && $config->get('app.debug') === true);
        foundationEnsure($config->get('allow') === ['new'] && $config->get('clear') === []);
    };

    $temporary = sys_get_temp_dir() . '/iris-foundation-' . bin2hex(random_bytes(8));
    if (!mkdir($temporary, 0700)) {
        throw new \RuntimeException('Cannot create foundation fixtures.');
    }
    $prefix = 'IRIS_FOUNDATION_' . strtoupper(bin2hex(random_bytes(8)));
    $environment = [];
    foreach (['TRUE', 'FALSE', 'NULL', 'INT', 'LEADING', 'HUGE', 'DECIMAL', 'EMPTY', 'KEEP', 'LOADED', 'QUOTED', 'LITERAL', 'PARTIAL', 'ENV_ONLY'] as $suffix) {
        $name = $prefix . '_' . $suffix;
        $environment[$name] = ['process' => getenv($name), 'exists' => array_key_exists($name, $_ENV), 'value' => $_ENV[$name] ?? null];
    }
    $tests['configuration directories load trusted arrays and reject invalid sources'] = static function () use ($temporary): void {
        $directory = $temporary . '/config';
        mkdir($directory, 0700);
        file_put_contents($directory . '/app.php', '<?php return ["debug" => false];');
        file_put_contents($directory . '/readme.txt', 'ignored');
        foundationEnsure(Config::fromDirectory($directory)->get('app.debug') === false);
        file_put_contents($directory . '/invalid.php', '<?php return "invalid";');
        foundationRejects(static fn() => Config::fromDirectory($directory), \UnexpectedValueException::class);
        unlink($directory . '/invalid.php');
        file_put_contents($temporary . '/outside.php', '<?php return [];');
        symlink($temporary . '/outside.php', $directory . '/linked.php');
        foundationRejects(static fn() => Config::fromDirectory($directory), \InvalidArgumentException::class);
        foundationRejects(static fn() => Config::fromDirectory($temporary . '/missing'), \InvalidArgumentException::class);
    };
    $tests['environment reads use documented types without losing identifiers'] = static function () use ($prefix): void {
        foreach (['TRUE' => 'true', 'FALSE' => 'FALSE', 'NULL' => 'null', 'INT' => '-42', 'LEADING' => '0042', 'HUGE' => '9223372036854775808123', 'DECIMAL' => '1.25', 'EMPTY' => ''] as $suffix => $value) {
            putenv($prefix . '_' . $suffix . '=' . $value);
        }
        foundationEnsure(Config::env($prefix . '_TRUE') === true && Config::env($prefix . '_FALSE') === false);
        foundationEnsure(Config::env($prefix . '_NULL', 'fallback') === null && Config::env($prefix . '_INT') === -42);
        foundationEnsure(Config::env($prefix . '_LEADING') === '0042' && Config::env($prefix . '_HUGE') === '9223372036854775808123');
        foundationEnsure(Config::env($prefix . '_DECIMAL') === '1.25' && Config::env($prefix . '_EMPTY', 'fallback') === '');
        foundationEnsure(Config::env($prefix . '_MISSING', 'fallback') === 'fallback');
        foundationRejects(static fn() => Config::env('bad=name'), \InvalidArgumentException::class);
    };
    $tests['environment loading is explicit literal and preserves existing values'] = static function () use ($temporary, $prefix): void {
        $file = $temporary . '/valid.env';
        putenv($prefix . '_KEEP=original');
        $_ENV[$prefix . '_ENV_ONLY'] = 'existing';
        file_put_contents($file, "# Synthetic fixture\n" . $prefix . "_KEEP=replacement\n" . $prefix . "_LOADED=42\n" . $prefix . "_QUOTED='two words'\n" . $prefix . '_LITERAL=${NOT_EXPANDED}' . "\n" . $prefix . "_ENV_ONLY=replacement\n");
        foundationEnsure(getenv($prefix . '_LOADED') === false);
        Config::loadEnv($file);
        foundationEnsure(Config::env($prefix . '_KEEP') === 'original' && Config::env($prefix . '_LOADED') === 42);
        foundationEnsure(Config::env($prefix . '_QUOTED') === 'two words' && Config::env($prefix . '_LITERAL') === '${NOT_EXPANDED}');
        foundationEnsure($_ENV[$prefix . '_ENV_ONLY'] === 'existing' && getenv($prefix . '_ENV_ONLY') === false);
        Config::loadEnv($file, true);
        foundationEnsure(Config::env($prefix . '_KEEP') === 'replacement');
    };
    $tests['malformed environment files fail before any mutation'] = static function () use ($temporary, $prefix): void {
        $file = $temporary . '/invalid.env';
        foreach (["malformed", $prefix . "_PARTIAL=second", "BAD NAME=value", $prefix . "_QUOTED='unfinished", "NULL_BYTE=bad\0value"] as $line) {
            file_put_contents($file, $prefix . "_PARTIAL=first\n" . $line . "\n");
            foundationRejects(static fn() => Config::loadEnv($file), \InvalidArgumentException::class);
            foundationEnsure(getenv($prefix . '_PARTIAL') === false);
        }
        symlink($file, $temporary . '/linked.env');
        foundationRejects(static fn() => Config::loadEnv($temporary . '/linked.env'), \InvalidArgumentException::class);
    };

    $tests['validation filters undeclared fields without coercing values'] = static function (): void {
        $input = ['age' => '42', 'email' => 'person@example.com', 'role' => 'admin'];
        $validated = Validator::validate($input, ['age' => 'required|integer|between:18,120', 'email' => 'required|email']);
        foundationEnsure($validated === ['age' => '42', 'email' => 'person@example.com']);
        foundationEnsure($input['role'] === 'admin');
    };
    $tests['required handles zero false null and missing values correctly'] = static function (): void {
        foreach ([0, '0', false] as $value) {
            foundationEnsure(Validator::validate(['value' => $value], ['value' => 'required']) === ['value' => $value]);
        }
        foreach ([null, '', '  ', []] as $value) {
            foundationEnsure(!Validator::check(['value' => $value], ['value' => 'required'])->valid());
        }
        foundationEnsure(!Validator::check([], ['value' => 'required'])->valid());
    };
    $tests['optional nullable and sometimes rules have explicit missing semantics'] = static function (): void {
        foundationEnsure(Validator::validate([], ['value' => 'integer']) === []);
        foundationEnsure(Validator::validate([], ['value' => 'sometimes|required|integer']) === []);
        foundationEnsure(Validator::validate(['value' => null], ['value' => 'nullable|integer']) === ['value' => null]);
        foundationEnsure(!Validator::check(['value' => null], ['value' => 'required|nullable'])->valid());
        foundationEnsure(!Validator::check(['value' => ''], ['value' => 'nullable|integer'])->valid());
    };
    $validCases = [
        ['array|min:2|max:2', ['one', 'two']], ['array|between:1,2', ['name' => 'value']],
        ['string|min:2|max:2', 'é🙂'],
        ['integer', PHP_INT_MAX], ['integer', '-42'], ['numeric|between:-1,2', '1.5'],
        ['boolean', false], ['boolean', '0'], ['email', 'person@example.com'],
        ['url', 'https://example.com/path?q=value'], ['in:red,blue', 'red'],
        ['date', '2024-02-29'], ['date:d/m/Y', '29/02/2024'],
        [['required', 'regex:/^(red|blue)$/'], 'blue'],
    ];
    foreach ($validCases as $index => [$rules, $value]) {
        $tests['valid application value ' . $index] = static fn() => foundationEnsure(Validator::check(['value' => $value], ['value' => $rules])->valid());
    }
    $invalidCases = [
        ['array', 'not-an-array'], ['array|min:2', [1]], ['array|max:1', [1, 2]], ['array|between:1,2', []],
        ['string', 42], ['string', "bad\xFF"], ['integer', true], ['integer', 42.0], ['integer', '0042'],
        ['integer', '9223372036854775808123'], ['numeric', INF], ['numeric', NAN], ['numeric', '1e309'],
        ['numeric', ' 1'], ['boolean', 'false'], ['email', 'invalid'], ['url', 'file:///etc/passwd'],
        ['string|min:3', 'é🙂'], ['string|max:1', 'é🙂'], ['integer|between:1,10', '11'],
        ['integer|min:' . PHP_INT_MAX, PHP_INT_MAX - 1], ['in:red,blue', 'RED'],
        ['date', '2023-02-29'], ['date', 'tomorrow'], [['regex:/^(red|blue)$/'], 'green'],
    ];
    foreach ($invalidCases as $index => [$rules, $value]) {
        $tests['invalid application value ' . $index] = static fn() => foundationEnsure(!Validator::check(['value' => $value], ['value' => $rules])->valid());
    }
    $tests['confirmation and comparison rules use strict matching'] = static function (): void {
        foundationEnsure(Validator::check(['password' => 'synthetic', 'password_confirmation' => 'synthetic'], ['password' => 'confirmed'])->valid());
        foundationEnsure(!Validator::check(['password' => 'synthetic'], ['password' => 'confirmed'])->valid());
        foundationEnsure(Validator::check(['value' => '42', 'other' => '42'], ['value' => 'same:other'])->valid());
        foundationEnsure(!Validator::check(['value' => '42', 'other' => 42], ['value' => 'same:other'])->valid());
    };
    $tests['array validation permits explicit recursive filtering without claiming nested validation'] = static function (): void {
        $input = ['lines' => [['sku' => 'example', 'quantity' => '2', 'role' => 'ignored']], 'unexpected' => true];
        $data = Validator::validate($input, ['lines' => ['required', 'array', 'min:1', static fn($value): bool => is_array($value) && array_is_list($value) && !array_filter($value, static fn($item): bool => !is_array($item))]]);
        foundationEnsure($data['lines'][0]['role'] === 'ignored');
        $data['lines'] = array_map(static fn(array $line): array => Validator::validate($line, ['sku' => 'required|string', 'quantity' => 'required|integer|min:1']), $data['lines']);
        foundationEnsure($data === ['lines' => [['sku' => 'example', 'quantity' => '2']]]);
    };
    $tests['validation results and exceptions expose structured errors without submitted values'] = static function (): void {
        $result = Validator::check(['age' => 'invalid-sensitive', 'name' => 'Valid', 'extra' => 'ignored'], ['age' => 'integer', 'name' => 'string']);
        foundationEnsure(!$result->valid() && $result->data() === ['name' => 'Valid']);
        foundationEnsure(isset($result->errors()['age']) && !str_contains(json_encode($result->errors()), 'invalid-sensitive'));
        try {
            Validator::validate(['age' => 'invalid-sensitive'], ['age' => 'integer']);
        } catch (ValidationException $exception) {
            foundationEnsure($exception->getStatus() === 422 && isset($exception->errors()['age']));
            return;
        }
        throw new \RuntimeException('Validation exception was not raised.');
    };
    $tests['custom validation rules receive context and preserve safe errors'] = static function (): void {
        $rules = ['quantity' => ['integer', static fn($value, array $input, string $field): bool => $field === 'quantity' && (int) $value % 2 === 0]];
        foundationEnsure(Validator::check(['quantity' => '4'], $rules)->valid());
        foundationEnsure(!Validator::check(['quantity' => '3'], $rules)->valid());
        $result = Validator::check(['quantity' => 3], ['quantity' => [static fn() => 'Use an even quantity.']]);
        foundationEnsure($result->errors()['quantity'] === ['Use an even quantity.']);
    };
    $tests['invalid validation definitions fail as configuration errors'] = static function (): void {
        foreach (['unknown', 'min:invalid', 'between:10,1', 'max', 'same:', 'regex:/[/', ['required' => true], [42]] as $rules) {
            foundationRejects(static fn() => Validator::check(['value' => 'test'], ['value' => $rules]), \InvalidArgumentException::class);
        }
        foundationRejects(static fn() => Validator::check([], ['bad.field' => 'string']), \InvalidArgumentException::class);
    };

    $passed = 0;
    $failures = [];
    try {
        foreach ($tests as $name => $test) {
            try {
                $test();
                $passed++;
            } catch (\Throwable $exception) {
                $failures[] = $name . ': ' . get_class($exception);
            }
        }
    } finally {
        foreach ($environment as $name => $original) {
            $original['process'] === false ? putenv($name) : putenv($name . '=' . $original['process']);
            if ($original['exists']) {
                $_ENV[$name] = $original['value'];
            } else {
                unset($_ENV[$name]);
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isLink() || $file->isFile() ? unlink($file->getPathname()) : rmdir($file->getPathname());
        }
        rmdir($temporary);
    }
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    echo $passed . '/' . count($tests) . ' foundation tests passed.' . PHP_EOL;
    exit($failures === [] ? 0 : 1);
}
