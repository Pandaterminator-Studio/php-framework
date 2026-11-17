<?php
namespace Core;
class View
{
    private static ?string $directory = null;
    private static ?\Core\View\Renderer $renderer = null;

    public static function configure(string $directory, string|false $cache = false): void
    {
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
            throw new \InvalidArgumentException('A readable view directory is required.');
        }
        $renderer = new \Core\View\Renderer($resolved, $cache);
        self::$directory = $resolved;
        self::$renderer = $renderer;
    }

    public static function render(string $view, array $args = []): void
    {
        $root = realpath(self::$directory ?? dirname(__DIR__) . '/App/Views');
        $file = $view !== '' && !str_contains($view, "\0") ? realpath($root . '/' . $view) : false;
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)
            || !is_file($file) || !is_readable($file)) {
            throw new \RuntimeException('View not found.');
        }
        extract($args, EXTR_SKIP);
        require $file;
    }

    public static function renderTemplate(string $template, array $args = []): void {
        self::$renderer ??= new \Core\View\Renderer(self::$directory ?? dirname(__DIR__) . '/App/Views');
        echo self::$renderer->render($template, $args);
    }
}
