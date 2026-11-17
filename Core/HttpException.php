<?php

namespace Core;

class HttpException extends \RuntimeException
{
    private int $status;

    public function __construct(int $status, string $publicMessage)
    {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('An HTTP error status must be between 400 and 599.');
        }
        $this->status = $status;
        parent::__construct($publicMessage, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
