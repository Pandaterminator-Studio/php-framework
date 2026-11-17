<?php

namespace Core\Cache;

final class ArrayCache extends AbstractCache
{
    private array $entries = [];

    protected function transaction(callable $operation): mixed
    {
        return $operation($this->entries);
    }
}
