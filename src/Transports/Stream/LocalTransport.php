<?php

declare(strict_types=1);

namespace Distributable\Transports\Stream;

use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Stream\Dispatcher;
use Illuminate\Contracts\Container\Container;

/**
 * No broker: an envelope goes to the handlers of the modules this process runs, as stream:consume would hand it over,
 * once the emitter's transaction has committed. A module another process runs never gets it.
 */
final class LocalTransport implements Transport
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Container $container,
    ) {}

    public function publish(Envelope $envelope): void
    {
        $dispatcher = $this->container->make(Dispatcher::class);

        foreach ($this->registry->localNames() as $consumer) {
            if ($envelope->isFor($consumer)) {
                $dispatcher->dispatch($envelope, $consumer);
            }
        }
    }

    public function consume(array $consumers, array $channels, callable $handle): void {}

    public function stop(): void {}
}
