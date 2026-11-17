<?php

namespace Core\Routing;

use Core\Container;
use Core\Http\Request;
use Core\HttpException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;

final class Invoker
{
    public static function invoke(callable $handler, Request $request, array $parameters, Container $container): mixed
    {
        $reflection = is_array($handler) ? new ReflectionMethod($handler[0], $handler[1]) : new ReflectionFunction(\Closure::fromCallable($handler));
        $arguments = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                $arguments[] = $request;
            } elseif (array_key_exists($parameter->getName(), $parameters)) {
                $arguments[] = self::routeValue($parameters[$parameter->getName()], $type);
            } elseif ($parameter->isVariadic()) {
                continue;
            } elseif ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                if ($container->has($type->getName())) {
                    $arguments[] = $container->get($type->getName());
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                } elseif ($type->allowsNull()) {
                    $arguments[] = null;
                } else {
                    $arguments[] = $container->get($type->getName());
                }
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new \LogicException('A handler argument cannot be resolved.');
            }
        }
        return $handler(...$arguments);
    }

    private static function routeValue(mixed $value, mixed $type): mixed
    {
        if ($type === null || $type instanceof ReflectionNamedType && in_array($type->getName(), ['string', 'mixed'], true)) {
            return $value;
        }
        if (!$type instanceof ReflectionNamedType) {
            throw new \LogicException('Route arguments require an unambiguous scalar type.');
        }
        if ($type->getName() === 'int') {
            $result = filter_var($value, FILTER_VALIDATE_INT);
            if ($result !== false) {
                return $result;
            }
        } elseif ($type->getName() === 'float') {
            $result = filter_var($value, FILTER_VALIDATE_FLOAT);
            if ($result !== false && is_finite($result)) {
                return $result;
            }
        } elseif ($type->getName() === 'bool' && in_array($value, ['0', '1', 'true', 'false'], true)) {
            return in_array($value, ['1', 'true'], true);
        }
        throw new HttpException(400, 'Invalid route parameter.');
    }
}
