<?php

namespace Core\Validation;

final class ValidationResult
{
    public function __construct(private array $validated, private array $validationErrors)
    {
    }

    public function valid(): bool
    {
        return $this->validationErrors === [];
    }

    public function data(): array
    {
        return $this->validated;
    }

    public function errors(): array
    {
        return $this->validationErrors;
    }
}
