<?php

declare(strict_types=1);

namespace Distributable\Config;

use Distributable\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository;

/** The distributable.events.* settings, typed and defaulted; read live, never snapshotted. */
final readonly class Streamer
{
    public function __construct(private Repository $config) {}

    public function getDefaultStream(): string
    {
        return (string) $this->config->get('distributable.events.stream', 'default');
    }

    /** @return array<string, mixed> */
    public function getStream(string $name): array
    {
        /** @var array<string, array<string, mixed>> $streams */
        $streams = $this->config->get('distributable.events.streams', []);

        return $streams[$name] ?? throw ConfigurationException::unknownStream($name);
    }

    /** The outbox table, in each emitting module's database. */
    public function getPublicationsTable(): string
    {
        return (string) $this->config->get('distributable.events.tables.publications', 'event_publications');
    }

    /** The consumption marks of the guard, in each consuming module's database. */
    public function getConsumptionsTable(): string
    {
        return (string) $this->config->get('distributable.events.tables.consumptions', 'event_consumptions');
    }

    /** Whether each event is stored in the emitting module's database, in the transaction, until it is sent. */
    public function publishesReliably(): bool
    {
        return (bool) $this->config->get('distributable.events.reliable_publish', false);
    }

    public function getGuard(): ?bool
    {
        $guard = $this->config->get('distributable.events.guard');

        return $guard === null ? null : (bool) $guard;
    }

    /** `acknowledge` deletes a consumption mark once its entry is acknowledged; anything else leaves it to stream:prune-consumptions. */
    public function getGuardCleanup(): string
    {
        return (string) $this->config->get('distributable.events.guard_cleanup', 'prune');
    }

    /** @return list<class-string> */
    public function getHandlers(string $event): array
    {
        /** @var array<string, list<class-string>> $listen */
        $listen = $this->config->get('distributable.events.listen', []);

        // Direct key access: event names carry dots, which config dot-notation would split.
        return $listen[$event] ?? [];
    }

    /** @return list<string> */
    public function getPropagate(): array
    {
        return array_values(array_map(strval(...), (array) $this->config->get('distributable.events.propagate', [])));
    }
}
