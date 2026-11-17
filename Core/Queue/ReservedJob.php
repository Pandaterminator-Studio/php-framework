<?php

namespace Core\Queue;

final readonly class ReservedJob
{
    public function __construct(
        public string $id,
        public string $name,
        public array $payload,
        public int $attempts,
        public int $maxAttempts,
        public string $token,
    ) {
    }
}
