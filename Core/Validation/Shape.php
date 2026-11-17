<?php

namespace Core\Validation;

final readonly class Shape
{
    private function __construct(public bool $isList, public string|array|self $rules)
    {
    }

    public static function object(array $fields): self
    {
        return new self(false, $fields);
    }

    public static function listOf(string|array|self $itemRules): self
    {
        return new self(true, $itemRules);
    }
}
