<?php

namespace Core\View;

use Core\Http\Response;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

final class Renderer
{
    private Environment $twig;

    public function __construct(string $templateDirectory, string|false $cache = false)
    {
        $root = realpath($templateDirectory);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new \InvalidArgumentException('The template directory is unavailable.');
        }
        $loader = new class($root) extends FilesystemLoader {
            public function __construct(private string $directory)
            {
                parent::__construct($directory);
            }

            protected function findTemplate(string $name, bool $throw = true): ?string
            {
                try {
                    if ($name === '' || $name[0] === '/' || $name[0] === '@' || preg_match('/[\x00-\x1f\x7f\\\\]/', $name)) {
                        throw new LoaderError('Template not found.');
                    }
                    $candidate = $this->directory;
                    foreach (explode('/', $name) as $segment) {
                        if ($segment === '' || $segment[0] === '.') {
                            throw new LoaderError('Template not found.');
                        }
                        $candidate .= DIRECTORY_SEPARATOR . $segment;
                        if (is_link($candidate)) {
                            throw new LoaderError('Template not found.');
                        }
                    }
                    $file = parent::findTemplate($name, $throw);
                    if ($file !== null && (!str_starts_with($file, $this->directory . DIRECTORY_SEPARATOR) || !is_readable($file))) {
                        throw new LoaderError('Template not found.');
                    }
                    return $file;
                } catch (LoaderError) {
                    if (!$throw) {
                        return null;
                    }
                    throw new LoaderError('Template not found.');
                }
            }
        };
        $this->twig = new Environment($loader, ['autoescape' => 'html', 'auto_reload' => true, 'cache' => $cache]);
    }

    public function render(string $template, array $args = []): string
    {
        return $this->twig->render($template, $args);
    }

    public function response(string $template, array $args = [], int $status = 200, array $headers = []): Response
    {
        return Response::html($this->render($template, $args), $status, $headers);
    }
}
