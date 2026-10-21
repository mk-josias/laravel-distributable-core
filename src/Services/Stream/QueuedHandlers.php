<?php

declare(strict_types=1);

namespace Distributable\Services\Stream;

use Distributable\Data\Envelope;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\Dispatcher as Events;
use ReflectionClass;

/**
 * Queues a handler that implements ShouldQueue as Laravel queues a listener (shouldQueue(), connection, queue, delay,
 * tries, unique…), with a job that runs it through the Dispatcher: in its module, under the guard.
 */
final class QueuedHandlers extends Events
{
    /** @var array{envelope: array<string, mixed>, module: string|null}|null */
    private ?array $pending = null;

    /** @param class-string $class */
    public function queue(string $class, Envelope $envelope, ?string $module): void
    {
        $arguments = [$envelope->name, $envelope->payload];

        if (! $this->handlerWantsToBeQueued($class, $arguments)) {
            return;
        }

        $this->pending = ['envelope' => $envelope->toArray(), 'module' => $module];

        try {
            $this->queueHandler($class, 'handle', $arguments);
        } finally {
            $this->pending = null;
        }
    }

    /**
     * @param  class-string  $class
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return array{object, mixed}
     */
    protected function createListenerAndJob($class, $method, $arguments): array
    {
        $listener = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $job = new CallQueuedHandler($class, $method, $arguments, $this->pending['envelope'] ?? [], $this->pending['module'] ?? null);

        return [$listener, $this->propagateListenerOptions($listener, $job)];
    }

    /**
     * Laravel calls connection() on what this returns: the queue manager.
     *
     * @return QueueFactory
     */
    protected function resolveQueue()
    {
        return $this->container->make(QueueFactory::class);
    }

    protected function resolveTransactionManager(): ?DatabaseTransactionsManager
    {
        return $this->container->bound('db.transactions') ? $this->container->make('db.transactions') : null;
    }
}
