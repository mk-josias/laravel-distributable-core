<?php

declare(strict_types=1);

namespace Distributable\Events;

use Distributable\Attributes\Emits;
use Distributable\Contracts\Stream\Event as EventContract;
use Distributable\Services\Discovery\ClassAttribute;

/**
 * Base for events: a concrete event says what it is with #[Emits] and returns its typed payload as
 * an array. Nothing else — identity, emitting module, propagated context and timestamp are the
 * Envelope's business, so the payload stays business only.
 */
abstract class Event implements EventContract
{
    /** @return array<string, mixed> */
    abstract public function payload(): array;

    /** @return list<string> the only modules that handle it; empty for every module */
    public function recipients(): array
    {
        return [];
    }

    /** The stream of distributable.events.streams it travels on: the one #[Emits] names, or the default one. */
    public function stream(): ?string
    {
        return ClassAttribute::of(static::class, Emits::class)?->stream;
    }
}
