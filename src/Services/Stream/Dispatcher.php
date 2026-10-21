<?php

declare(strict_types=1);

namespace Distributable\Services\Stream;

use Distributable\Config\Streamer;
use Distributable\Context\ModuleContext;
use Distributable\Contracts\Stream\Handler;
use Distributable\Contracts\Stream\Idempotent;
use Distributable\Contracts\Stream\RedeliversEnvelopes;
use Distributable\Data\Envelope;
use Distributable\Events\ShadowChanged;
use Distributable\Events\ShadowWanted;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Handlers\AnnounceShadowSource;
use Distributable\Handlers\SyncShadows;
use Distributable\Services\ModuleRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;

/**
 * Runs the local handlers of an envelope. Every transport ends here — in-process delivery and a
 * broker consumer take exactly the same path, so a handler behaves the same wherever its emitter runs.
 *
 * When delivery is at-least-once, each run is guarded: the mark in `event_consumptions` and the
 * handler's own writes share one transaction on the consuming module's database, so a replay is
 * a no-op and a failed handler leaves no mark behind. Handlers become idempotent by
 * construction — their author writes nothing. The guard covers what a handler writes to its
 * database; effects outside it (mail, third-party calls) stay at-least-once.
 */
final class Dispatcher
{
    public function __construct(
        private readonly Container $container,
        private readonly Streamer $config,
        private readonly ModuleRegistry $registry,
        private readonly ModuleContext $context,
        private readonly TransportManager $transports,
        private readonly PayloadVersions $versions,
    ) {}

    /**
     * With a $consumer, only its handlers run: a process running several modules has one consumer per module.
     * A handler that implements ShouldQueue goes on the queue, as a Laravel listener does.
     */
    public function dispatch(Envelope $envelope, ?string $consumer = null): void
    {
        foreach ($this->handlersFor($envelope->name) as $class) {
            $owner = $this->registry->ownerOf($class)?->name;

            if ($consumer !== null && $owner !== null && $owner !== $consumer) {
                continue;
            }

            is_subclass_of($class, ShouldQueue::class)
                ? $this->container->make(QueuedHandlers::class)->queue($class, $envelope, $owner ?? $consumer)
                : $this->handle($class, $envelope, $owner ?? $consumer);
        }
    }

    /**
     * Runs one handler now, guarded when delivery is at-least-once.
     *
     * @param  class-string  $class
     */
    public function handle(string $class, Envelope $envelope, ?string $module): void
    {
        $this->guarded($envelope->stream) && ! is_subclass_of($class, Idempotent::class)
            ? $this->runGuarded($class, $envelope)
            : $this->run($class, $envelope, $module);
    }

    /** @return list<class-string> the package's own handlers, then the application's */
    public function handlersFor(string $name): array
    {
        $builtIn = [
            ShadowChanged::NAME => [SyncShadows::class],
            ShadowWanted::NAME => [AnnounceShadowSource::class],
        ];

        return [...($builtIn[$name] ?? []), ...$this->config->getHandlers($name)];
    }

    public function guarded(?string $stream = null): bool
    {
        $stream ??= $this->config->getDefaultStream();

        return $this->config->getGuard()
            ?? ($this->config->publishesReliably() || $this->transports->stream($stream) instanceof RedeliversEnvelopes);
    }

    /** Deletes the consumption marks of an envelope its consumer has acknowledged: it can no longer be redelivered. */
    public function forget(Envelope $envelope, string $consumer): void
    {
        if (! $this->guarded($envelope->stream)) {
            return;
        }

        $this->context->connection($consumer)
            ->table($this->config->getConsumptionsTable())
            ->where('event_id', $envelope->id)
            ->delete();
    }

    /** @param class-string $class */
    private function runGuarded(string $class, Envelope $envelope): void
    {
        $module = $this->registry->ownerOf($class)->name
            ?? throw ModuleException::outsideModule($class);

        $connection = $this->context->connection($module);

        $connection->transaction(function () use ($connection, $class, $envelope, $module): void {
            $claimed = $connection->table($this->config->getConsumptionsTable())->insertOrIgnore([
                'event_id' => $envelope->id,
                'handler' => $class,
                'consumed_at' => Date::now(),
            ]);

            if ($claimed === 0) {
                return; // already handled — the replay stops here
            }

            $this->run($class, $envelope, $module);
        });
    }

    /**
     * @param  class-string  $class
     * @param  string|null  $module  the handler's own module, or the consumer's for one of the package
     */
    private function run(string $class, Envelope $envelope, ?string $module): void
    {
        $handler = $this->container->make($class);

        if (! $handler instanceof Handler) {
            throw ConfigurationException::invalidHandler($class, Handler::class);
        }

        $payload = $this->versions->lift($envelope);

        $this->context->within(
            $module === null ? null : $this->registry->get($module),
            fn () => $this->withContext($envelope->headers, static fn () => $handler->handle($envelope->name, $payload)),
        );
    }

    /**
     * Runs the handler under the emitter's propagated context, then puts the caller's back.
     *
     * @param  array<string, mixed>  $headers
     */
    private function withContext(array $headers, callable $callback): void
    {
        $previous = Context::only(array_keys($headers));
        Context::add($headers);

        try {
            $callback();
        } finally {
            Context::forget(array_keys($headers));
            Context::add($previous);
        }
    }
}
