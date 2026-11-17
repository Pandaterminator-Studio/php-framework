<?php

namespace Core\Queue;

interface JobHandler
{
    public function handle(array $payload, string $jobId): void;
}
