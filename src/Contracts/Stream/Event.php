<?php

declare(strict_types=1);

namespace Distributable\Contracts\Stream;

/** What the bus emits: a name and a payload; Events\Event implements the rest with its defaults. */
interface Event
{
    public function name(): string;

    /** @return array<string, mixed> */
    public function payload(): array;

    /** @return list<string> the only modules that handle it; empty for every module */
    public function recipients(): array;

    /** The stream of distributable.events.streams it travels on; null for the default one. */
    public function stream(): ?string;

    /** The version of the payload's shape: raised when a field is renamed, removed or changes meaning. */
    public function version(): int;
}
