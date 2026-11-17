<?php

namespace Core\Queue;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

final class Worker
{
    public function __construct(
        private DatabaseQueue $queue,
        private ContainerInterface $container,
        private array $handlers,
        private int $leaseSeconds = 60,
        private int $retryDelay = 5,
        private ?LoggerInterface $logger = null,
    ) {
        if ($leaseSeconds < 1 || $leaseSeconds > DatabaseQueue::MAX_LEASE || $retryDelay < 0 || $retryDelay > DatabaseQueue::MAX_DELAY) {
            throw new InvalidArgumentException('Invalid queue lease or retry delay.');
        }
        foreach ($handlers as $name => $class) {
            if (!is_string($name) || !DatabaseQueue::validName($name) || !is_string($class) || !is_a($class, JobHandler::class, true)) {
                throw new InvalidArgumentException('Map queue job names to JobHandler implementations.');
            }
        }
    }

    public function runNext(): string
    {
        $job = $this->queue->reserve($this->leaseSeconds);
        if ($job === null) {
            return 'empty';
        }
        try {
            $class = $this->handlers[$job->name] ?? throw new UnexpectedValueException('Unregistered queue job.');
            $handler = $this->container->get($class);
            if (!$handler instanceof JobHandler) {
                throw new UnexpectedValueException('The configured service must implement JobHandler.');
            }
            $handler->handle($job->payload, $job->id);
        } catch (Throwable $exception) {
            $outcome = $job->attempts >= $job->maxAttempts ? 'failed' : 'released';
            if (!$this->queue->release($job, $this->retryDelay)) {
                $outcome = 'lost';
            }
            $this->log($job, $outcome, $exception);
            return $outcome;
        }
        if ($this->queue->complete($job)) {
            return 'completed';
        }
        $this->log($job, 'lost');
        return 'lost';
    }

    private function log(ReservedJob $job, string $outcome, ?Throwable $exception = null): void
    {
        try {
            $this->logger?->warning('Queue job did not complete.', [
                'job_id' => $job->id,
                'job_name' => $job->name,
                'attempt' => $job->attempts,
                'outcome' => $outcome,
                'exception_type' => $exception === null ? null : get_debug_type($exception),
            ]);
        } catch (Throwable) {
        }
    }
}
