<?php

namespace Core\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class Scaffolder
{
    public function __construct(private string $frameworkPath)
    {
    }

    public function project(string $target): string
    {
        if ($target === '' || preg_match('/[\x00-\x1f\x7f]/', $target) || in_array(basename($target), ['.', '..', ''], true) || is_link($target)) {
            throw new ConsoleException('Choose a regular application directory.');
        }
        if (file_exists($target) && (!is_dir($target) || (scandir($target) ?: []) !== ['.', '..'])) {
            throw new ConsoleException('The destination must be absent or an empty directory.');
        }
        $created = [];
        $directories = [];
        try {
            $this->directory($target, $directories);
            $root = realpath($target);
            if ($root === false) {
                throw new ConsoleException('The application directory could not be resolved.');
            }
            $source = $this->frameworkPath . '/skeleton';
            if (!is_dir($source)) {
                throw new ConsoleException('The framework application skeleton is missing.');
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink() || !$file->isFile()) {
                    throw new ConsoleException('The application skeleton contains an unsupported entry.');
                }
                $relative = substr($file->getPathname(), strlen($source) + 1);
                $destination = $root . '/' . $relative;
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    throw new ConsoleException('A skeleton file could not be read.');
                }
                if ($relative === 'composer.json') {
                    $composer = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
                    $composer['repositories'][0]['url'] = $this->frameworkPath;
                    $contents = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                }
                $this->directory(dirname($destination), $directories);
                $this->write($destination, $contents);
                $created[] = $destination;
            }
            return $root;
        } catch (Throwable $exception) {
            foreach (array_reverse($created) as $file) {
                @unlink($file);
            }
            foreach (array_reverse($directories) as $directory) {
                @rmdir($directory);
            }
            throw $exception;
        }
    }

    public function controller(string $base, string $name): string
    {
        $this->className($name);
        $source = "<?php\n\nnamespace App\\Controllers;\n\nuse Core\\Http\\Request;\nuse Core\\Http\\Response;\n\nfinal class " . $name . "\n{\n    public function __invoke(Request \$request): Response\n    {\n        return Response::json(['data' => []]);\n    }\n}\n";
        return $this->applicationFile($base, 'app/Controllers/' . $name . '.php', $source);
    }

    public function middleware(string $base, string $name): string
    {
        $this->className($name);
        $source = "<?php\n\nnamespace App\\Middleware;\n\nuse Core\\Http\\Request;\nuse Core\\Http\\Response;\nuse Core\\Middleware\\MiddlewareInterface;\n\nfinal class " . $name . " implements MiddlewareInterface\n{\n    public function process(Request \$request, callable \$next): Response\n    {\n        return \$next(\$request);\n    }\n}\n";
        return $this->applicationFile($base, 'app/Middleware/' . $name . '.php', $source);
    }

    public function job(string $base, string $name): string
    {
        $this->className($name);
        $source = <<<PHP
<?php

namespace App\\Jobs;

use Core\\Queue\\JobHandler;

final class {$name} implements JobHandler
{
    public function handle(array \$payload, string \$jobId): void
    {
        throw new \\LogicException('Implement the job handler before registering it.');
    }
}
PHP;
        return $this->applicationFile($base, 'app/Jobs/' . $name . '.php', $source . "\n");
    }

    public function migration(string $base, string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,100}$/D', $name)) {
            throw new ConsoleException('Migration names must use lowercase letters, numbers and underscores, starting with a letter.');
        }
        $source = "<?php\n\nuse Core\\Database\\Connection;\nuse Core\\Database\\MigrationInterface;\n\nreturn new class implements MigrationInterface {\n    public function up(Connection \$connection): void\n    {\n    }\n\n    public function down(Connection \$connection): void\n    {\n    }\n};\n";
        return $this->applicationFile($base, 'database/migrations/' . gmdate('YmdHis') . '_' . $name . '.php', $source);
    }

    private function className(string $name): void
    {
        if (!preg_match('/^[A-Z][a-zA-Z0-9]{0,100}$/D', $name)
            || in_array(strtolower($name), ['class', 'interface', 'trait', 'enum', 'readonly', 'abstract', 'final', 'static', 'self', 'parent', 'true', 'false', 'null', 'match', 'fn', 'function', 'namespace', 'use', 'new', 'return', 'throw', 'yield', 'clone', 'extends', 'implements', 'int', 'float', 'bool', 'string', 'void', 'iterable', 'object', 'mixed', 'never', 'resource', 'numeric'], true)) {
            throw new ConsoleException('Use a simple class name starting with an uppercase letter.');
        }
        if (!function_exists('token_get_all')) {
            throw new ConsoleException('Class generation requires the PHP tokenizer extension.');
        }
        try {
            token_get_all('<?php class ' . $name . ' {}', TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new ConsoleException('The class name is reserved by PHP.');
        }
    }

    private function applicationFile(string $base, string $relative, string $source): string
    {
        $directories = [];
        $parent = $base;
        foreach (explode('/', dirname($relative)) as $segment) {
            $parent .= '/' . $segment;
            if (is_link($parent)) {
                throw new ConsoleException('Generated application directories cannot be symbolic links.');
            }
            $this->directory($parent, $directories);
        }
        $path = $base . '/' . $relative;
        $this->write($path, $source);
        return $path;
    }

    private function directory(string $path, array &$created): void
    {
        if (is_dir($path)) {
            return;
        }
        if (file_exists($path) || is_link($path)) {
            throw new ConsoleException('A required directory conflicts with an existing file.');
        }
        $parent = dirname($path);
        if ($parent !== $path) {
            $this->directory($parent, $created);
        }
        if (!@mkdir($path, 0755)) {
            throw new ConsoleException('An application directory could not be created.');
        }
        $created[] = $path;
    }

    private function write(string $path, string $source): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new ConsoleException('A generated file already exists or cannot be created.');
        }
        try {
            $offset = 0;
            while ($offset < strlen($source)) {
                $written = fwrite($handle, substr($source, $offset));
                if ($written === false || $written === 0) {
                    throw new ConsoleException('A generated file could not be written completely.');
                }
                $offset += $written;
            }
        } catch (Throwable $exception) {
            fclose($handle);
            $handle = null;
            @unlink($path);
            throw $exception;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
