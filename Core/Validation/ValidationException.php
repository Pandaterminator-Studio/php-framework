<?php

namespace Core\Validation;

final class ValidationException extends \Core\HttpException
{
    private array $validationErrors;

    public function __construct(array $errors)
    {
        parent::__construct(422, 'Validation failed.');
        $this->validationErrors = $errors;
    }

    public function errors(): array
    {
        return $this->validationErrors;
    }
}
