<?php

namespace {
    require dirname(__DIR__) . '/.iris/vendor/autoload.php';
}

namespace App\Controllers {
    final class CoreFixture extends \Core\Controller
    {
        public static array $events = [];

        protected function before(): bool
        {
            self::$events[] = 'before';
            return true;
        }

        protected function after(): void
        {
            self::$events[] = 'after';
        }

        public function indexAction(): void
        {
            self::$events[] = 'index';
        }

        public function addNewAction(): void
        {
            self::$events[] = 'addNew';
        }

        public function transactionAction(): void
        {
            self::$events[] = 'transaction';
        }
    }

    abstract class CoreAbstractFixture extends \Core\Controller
    {
        public function indexAction(): void
        {
            CoreFixture::$events[] = 'abstract';
        }
    }

    final class CoreUnrelatedFixture
    {
        public function __construct()
        {
            CoreFixture::$events[] = 'unrelated';
        }
    }
}

namespace {
    use App\Controllers\CoreFixture;
    use Core\HttpException;
    use Core\RequestGuard;
    use Core\Router;
    use Core\View;

    function coreEnsure(bool $condition, string $message = 'Assertion failed.'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function coreExpectFailure(callable $operation, string $type): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            coreEnsure($exception instanceof $type, 'Unexpected exception type.');
            return;
        }
        throw new \RuntimeException('Expected rejection was not raised.');
    }

    function coreExpectNotFound(callable $operation): void
    {
        try {
            $operation();
        } catch (HttpException $exception) {
            coreEnsure($exception->getStatus() === 404, 'Expected HTTP 404.');
            return;
        }
        throw new \RuntimeException('Expected HTTP 404 was not raised.');
    }

    function coreCapture(callable $operation): string
    {
        ob_start();
        try {
            $operation();
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    function coreFixtureRouter(): Router
    {
        $router = new Router();
        $router->add('', ['controller' => 'CoreFixture', 'action' => 'index']);
        $router->add('{controller}/{action}');
        return $router;
    }

    $tests = [];
    $tests['literal route punctuation is escaped'] = static function (): void {
        $router = new Router();
        $router->add('report.json', ['controller' => 'CoreFixture', 'action' => 'index']);
        $router->add('docs/(draft)', ['controller' => 'CoreFixture', 'action' => 'index']);
        coreEnsure($router->match('report.json'));
        coreEnsure(!$router->match('reportXjson'));
        coreEnsure($router->match('docs/(draft)'));
        coreEnsure(!$router->match('docs/draft'));
    };
    $tests['custom parameters support bounded quantifiers'] = static function (): void {
        $router = new Router();
        $router->add('records/{id:\d{1,3}}', ['controller' => 'CoreFixture', 'action' => 'index']);
        foreach (['1', '42', '999'] as $identifier) {
            coreEnsure($router->match('records/' . $identifier));
            coreEnsure($router->getParams()['id'] === $identifier);
        }
        coreEnsure(!$router->match('records/1000'));
        coreEnsure(!$router->match('records/no'));
    };
    $tests['root route and hyphenated actions dispatch through hooks'] = static function (): void {
        CoreFixture::$events = [];
        $router = coreFixtureRouter();
        $router->dispatch('', new RequestGuard('GET'));
        coreEnsure(CoreFixture::$events === ['before', 'index', 'after']);
        CoreFixture::$events = [];
        $router->dispatch('core-fixture/add-new', new RequestGuard('GET'));
        coreEnsure(CoreFixture::$events === ['before', 'addNew', 'after']);
    };
    $tests['failed matches clear stale route parameters'] = static function (): void {
        $router = coreFixtureRouter();
        coreEnsure($router->match('core-fixture/index'));
        coreEnsure($router->getParams() !== []);
        coreEnsure(!$router->match('missing/route/extra'));
        coreEnsure($router->getParams() === []);
    };
    $tests['legitimate action names may end in the letters action'] = static function (): void {
        CoreFixture::$events = [];
        $router = coreFixtureRouter();
        $router->dispatch('core-fixture/transaction', new RequestGuard('GET'));
        coreEnsure(CoreFixture::$events === ['before', 'transaction', 'after']);
    };
    $tests['ampersand and equals paths cannot fall back to home'] = static function (): void {
        $router = coreFixtureRouter();
        CoreFixture::$events = [];
        foreach (['&role=admin', 'role=admin', 'core-fixture/index&role=admin', 'core-fixture/index=admin'] as $path) {
            coreExpectNotFound(static fn() => $router->dispatch($path, new RequestGuard('GET')));
        }
        coreEnsure(CoreFixture::$events === []);
    };
    $tests['routes require an exact end of input'] = static function (): void {
        $router = coreFixtureRouter();
        coreEnsure(!$router->match("core-fixture/index\n"));
        coreEnsure(!$router->match("\n"));
        $router->add('report.json', ['controller' => 'CoreFixture', 'action' => 'index']);
        coreEnsure(!$router->match("report.json\n"));
    };
    $tests['invalid custom expressions and duplicate names are rejected'] = static function (): void {
        $router = new Router();
        coreExpectFailure(static fn() => $router->add('broken/{id:(}'), \InvalidArgumentException::class);
        coreExpectFailure(static fn() => $router->add('broken/{id:[0-9}'), \InvalidArgumentException::class);
        coreExpectFailure(static fn() => $router->add('records/{id:\d+}/{id:\d+}'), \InvalidArgumentException::class);
        coreEnsure($router->getRoutes() === []);
    };
    $tests['abstract and unrelated classes cannot execute as controllers'] = static function (): void {
        CoreFixture::$events = [];
        $router = coreFixtureRouter();
        coreExpectNotFound(static fn() => $router->dispatch('core-abstract-fixture/index', new RequestGuard('GET')));
        coreExpectNotFound(static fn() => $router->dispatch('core-unrelated-fixture/index', new RequestGuard('GET')));
        coreEnsure(CoreFixture::$events === []);
    };
    $tests['invalid controller namespaces fail before action execution'] = static function (): void {
        CoreFixture::$events = [];
        foreach (['', '..', '../Core', 'Valid\\..', 'Valid\\', ['Invalid']] as $namespace) {
            $router = new Router();
            $router->add('invalid', ['controller' => 'CoreFixture', 'action' => 'index', 'namespace' => $namespace]);
            coreExpectNotFound(static fn() => $router->dispatch('invalid', new RequestGuard('GET')));
        }
        coreEnsure(CoreFixture::$events === []);
    };
    $tests['framework dispatcher cannot be routed as an inherited action'] = static function (): void {
        CoreFixture::$events = [];
        $router = coreFixtureRouter();
        foreach (['core-fixture/execute', 'core-fixture/execute-action', 'core-fixture/index-action'] as $path) {
            coreExpectNotFound(static fn() => $router->dispatch($path, new RequestGuard('GET')));
        }
        coreEnsure(CoreFixture::$events === []);
    };

    $fixtureName = '__iris_core_test_' . bin2hex(random_bytes(8));
    $viewRoot = dirname(__DIR__) . '/App/Views';
    $viewDirectory = $viewRoot . '/' . $fixtureName;
    $externalDirectory = sys_get_temp_dir() . '/' . $fixtureName;
    if (!mkdir($viewDirectory, 0700) || !mkdir($externalDirectory, 0700)) {
        throw new \RuntimeException('Cannot create isolated view fixtures.');
    }
    $normalFile = $viewDirectory . '/normal.php';
    $externalFile = $externalDirectory . '/outside.php';
    $linkedFile = $viewDirectory . '/outside.php';
    $twigFile = $viewDirectory . '/escape.html';
    file_put_contents($normalFile, '<?php echo htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");');
    file_put_contents($externalFile, '<?php echo "outside";');
    file_put_contents($twigFile, '{{ value }}');
    if (!symlink($externalFile, $linkedFile)) {
        throw new \RuntimeException('Cannot create isolated symlink fixture.');
    }

    $tests['PHP view renders supplied arguments'] = static function () use ($fixtureName): void {
        $output = coreCapture(static fn() => View::render($fixtureName . '/normal.php', ['name' => '<safe>']));
        coreEnsure($output === '&lt;safe&gt;');
    };
    $tests['view arguments cannot replace the resolved template path'] = static function () use ($fixtureName, $externalFile): void {
        $output = coreCapture(static fn() => View::render($fixtureName . '/normal.php', [
            'name' => 'inside', 'file' => $externalFile, 'view' => $externalFile, 'root' => dirname($externalFile),
        ]));
        coreEnsure($output === 'inside');
    };
    $tests['PHP view rejects traversal and symlinks outside the view root'] = static function () use ($fixtureName): void {
        coreExpectFailure(static fn() => View::render('../../README.md'), \RuntimeException::class);
        coreExpectFailure(static fn() => View::render($fixtureName . '/outside.php'), \RuntimeException::class);
    };
    $tests['PHP view rejects empty directories null bytes and missing files'] = static function () use ($fixtureName): void {
        foreach (['', '.', $fixtureName, "bad\0.php", $fixtureName . '/missing.php'] as $view) {
            coreExpectFailure(static fn() => View::render($view), \RuntimeException::class);
        }
    };
    $tests['Twig escapes HTML template data'] = static function () use ($fixtureName): void {
        $output = coreCapture(static fn() => View::renderTemplate($fixtureName . '/escape.html', ['value' => '<script>alert(1)</script>']));
        coreEnsure($output === '&lt;script&gt;alert(1)&lt;/script&gt;');
    };
    $tests['legacy views can use a consumer-owned template directory'] = static function () use ($externalDirectory, $viewRoot): void {
        $template = $externalDirectory . '/consumer.html';
        file_put_contents($template, '{{ value }}');
        try {
            View::configure($externalDirectory);
            coreEnsure(coreCapture(static fn() => View::render('outside.php')) === 'outside');
            coreEnsure(coreCapture(static fn() => View::renderTemplate('consumer.html', ['value' => '<safe>'])) === '&lt;safe&gt;');
        } finally {
            View::configure($viewRoot);
            unlink($template);
        }
    };

    $passed = 0;
    $failures = [];
    try {
        foreach ($tests as $name => $test) {
            try {
                $test();
                $passed++;
            } catch (\Throwable $exception) {
                $failures[] = $name . ': ' . get_class($exception) . ' - ' . $exception->getMessage();
            }
        }
    } finally {
        foreach ([$linkedFile, $normalFile, $twigFile, $externalFile] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        rmdir($viewDirectory);
        rmdir($externalDirectory);
    }
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    echo $passed . '/' . count($tests) . ' core regression tests passed.' . PHP_EOL;
    exit($failures === [] ? 0 : 1);
}
