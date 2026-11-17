<?php

namespace Core;

use Core\Container\ContainerException;
use Core\Container\NotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

final class Container implements ContainerInterface
{
    private array $bindings = [];
    private array $instances = [];
    private array $resolving = [];

    public function bind(string $id, callable|string $concrete): void
    {
        $this->register($id, $concrete, false);
    }

    public function singleton(string $id, callable|string $concrete): void
    {
        $this->register($id, $concrete, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->assertId($id);
        $this->instances[$id] = $instance;
    }

    public function withInstance(string $id, object $instance, callable $operation): mixed
    {
        $previous = $this->instances[$id] ?? null;
        $this->instance($id, $instance);
        try {
            return $operation();
        } finally {
            if ($previous === null) {
                unset($this->instances[$id]);
            } else {
                $this->instances[$id] = $previous;
            }
        }
    }

    public function has(string $id): bool
    {
        if ($id === '' || trim($id) !== $id || str_contains($id, "\0")) {
            return false;
        }
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->bindings)
            || (class_exists($id) && (new ReflectionClass($id))->isInstantiable());
    }

    public function bound(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->bindings);
    }

    public function get(string $id): mixed
    {
        $this->assertId($id);
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!array_key_exists($id, $this->bindings) && (!class_exists($id) || !(new ReflectionClass($id))->isInstantiable())) {
            throw new NotFoundException('Container entry was not found: ' . $id);
        }
        $instance = $this->resolve($id, function () use ($id): object {
            if (!array_key_exists($id, $this->bindings)) {
                return $this->build($id, []);
            }
            $concrete = $this->bindings[$id]['concrete'];
            if (is_string($concrete)) {
                return $concrete === $id ? $this->build($concrete, []) : $this->get($concrete);
            }
            $result = $concrete($this);
            if (!is_object($result)) {
                throw new ContainerException('Container factories must return objects.');
            }
            return $result;
        });
        if (($this->bindings[$id]['shared'] ?? false) === true) {
            $this->instances[$id] = $instance;
        }
        return $instance;
    }

    public function make(string $class, array $parameters = []): object
    {
        $this->assertId($class);
        return $this->resolve($class, fn(): object => $this->build($class, $parameters));
    }

    private function register(string $id, callable|string $concrete, bool $shared): void
    {
        $this->assertId($id);
        if (is_string($concrete)) {
            $this->assertId($concrete);
            if (is_callable($concrete) && !class_exists($concrete)) {
                $concrete = \Closure::fromCallable($concrete);
            }
        }
        $this->bindings[$id] = ['concrete' => $concrete, 'shared' => $shared];
        unset($this->instances[$id]);
    }

    private function assertId(string $id): void
    {
        if ($id === '' || trim($id) !== $id || str_contains($id, "\0")) {
            throw new NotFoundException('Container entry IDs must be nonempty strings without surrounding whitespace or null bytes.');
        }
    }

    private function resolve(string $id, callable $factory): object
    {
        if (in_array($id, $this->resolving, true)) {
            throw new ContainerException('Circular container dependency: ' . implode(' -> ', array_merge($this->resolving, [$id])));
        }
        $this->resolving[] = $id;
        try {
            return $factory();
        } catch (ContainerException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContainerException('Container entry could not be constructed: ' . $id, 0, $exception);
        } finally {
            array_pop($this->resolving);
        }
    }

    private function build(string $class, array $parameters): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException('Container class was not found: ' . $class);
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException('Container classes must be instantiable: ' . $class);
        }
        $constructor = $reflection->getConstructor();
        $declared = $constructor === null ? [] : $constructor->getParameters();
        $names = array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $declared);
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || !in_array($name, $names, true)) {
                throw new ContainerException('Constructor overrides must use declared parameter names.');
            }
        }
        $arguments = [];
        foreach ($declared as $parameter) {
            $name = $parameter->getName();
            if ($parameter->isPassedByReference()) {
                throw new ContainerException('Constructors with reference parameters require an explicit factory.');
            }
            if ($parameter->isVariadic()) {
                $values = array_key_exists($name, $parameters) ? $parameters[$name] : [];
                if (!is_array($values) || !array_is_list($values)) {
                    throw new ContainerException('Variadic overrides must be lists.');
                }
                array_push($arguments, ...$values);
            } elseif (array_key_exists($name, $parameters)) {
                $arguments[] = $parameters[$name];
            } else {
                $arguments[] = $this->argument($parameter);
            }
        }
        return $reflection->newInstanceArgs($arguments);
    }

    private function argument(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();
            if ($name === 'self') {
                $name = $parameter->getDeclaringClass()->getName();
            } elseif ($name === 'parent') {
                $name = $parameter->getDeclaringClass()->getParentClass()?->getName() ?? $name;
            }
            if ($this->has($name)) {
                return $this->get($name);
            }
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($type !== null && $type->allowsNull()) {
            return null;
        }
        throw new ContainerException('Cannot resolve constructor parameter: ' . $parameter->getName());
    }
}
