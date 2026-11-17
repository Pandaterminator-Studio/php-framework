<?php

namespace Core\Routing;

use Core\Controller;
use InvalidArgumentException;

final class Route
{
    private array $middleware = [];
    private ?array $policy = null;
    private ?string $name = null;
    private bool $stateless = false;

    public function __construct(private array $methods, private string $path, private mixed $handler, private string $expression, private ?\Closure $nameValidator = null)
    {
    }

    public function methods(): array { return $this->methods; }
    public function path(): string { return $this->path; }
    public function handler(): mixed { return $this->handler; }
    public function expression(): string { return $this->expression; }
    public function middlewareList(): array { return $this->middleware; }
    public function requestPolicy(): ?array { return $this->policy; }
    public function routeName(): ?string { return $this->name; }
    public function isStateless(): bool { return $this->stateless; }

    public function name(string $name): self
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $name)) {
            throw new InvalidArgumentException('Invalid route name.');
        }
        if ($this->nameValidator !== null) {
            ($this->nameValidator)($this, $name);
        }
        $this->name = $name;
        return $this;
    }

    public function middleware(mixed ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    public function policy(array $policy): self
    {
        $this->policy = array_replace(['methods' => $this->methods], $policy);
        return $this;
    }

    public function stateless(bool $enabled = true): self
    {
        $this->stateless = $enabled;
        return $this;
    }

    public function scope(): string
    {
        return 'route:' . implode('|', $this->methods) . ':' . $this->path . ':' . ($this->name ?? '');
    }
}
