<?php

namespace Core\Events;

final class Dispatcher
{
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->eventName($event);
        $this->listeners[$event][] = \Closure::fromCallable($listener);
    }

    public function dispatch(string $event, mixed $payload = null): array
    {
        $this->eventName($event);
        $results = [];
        $listeners = $this->listeners[$event] ?? [];
        foreach ($listeners as $listener) {
            $results[] = $listener($payload, $event);
        }
        return $results;
    }

    private function eventName(string $event): void
    {
        if ($event === '' || strlen($event) > 256 || str_contains($event, "\0")) {
            throw new \InvalidArgumentException('Event names must contain between 1 and 256 bytes without null bytes.');
        }
    }
}
