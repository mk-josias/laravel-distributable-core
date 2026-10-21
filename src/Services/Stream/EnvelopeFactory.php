<?php

declare(strict_types=1);

namespace Distributable\Services\Stream;

use Distributable\Attributes\Emits;
use Distributable\Config\Streamer;
use Distributable\Contracts\Stream\Event;
use Distributable\Data\Envelope;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Discovery\ClassAttribute;
use Distributable\Services\ModuleRegistry;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/** Stamps an event with its name, version, emitting module and the propagated context — the only place that does. */
final class EnvelopeFactory
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Streamer $config,
        private readonly PayloadVersions $versions,
    ) {}

    public function for(Event $event, ?string $emitter = null): Envelope
    {
        $emits = ClassAttribute::of($event::class, Emits::class)
            ?? throw ConfigurationException::missingAttribute($event::class, Emits::class);

        $emitter ??= $emits->module
            ?? $this->registry->ownerOf($event::class)->name
            ?? throw ModuleException::outsideModule($event::class);

        return new Envelope(
            // uuid7: time-ordered, so the unique index stays local.
            id: (string) Str::uuid7(),
            emitter: $emitter,
            name: $emits->name,
            payload: $event->payload(),
            headers: array_filter(Context::only($this->config->getPropagate()), static fn (mixed $value): bool => $value !== null),
            emittedAt: Date::now()->toImmutable(),
            stream: $event->stream() ?? $this->config->getDefaultStream(),
            recipients: $event->recipients(),
            version: $this->versions->current($emits->name),
        );
    }
}
