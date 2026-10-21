<?php

declare(strict_types=1);

namespace Distributable\Contracts\Stream;

/** What the bus emits: a payload; its name comes from #[Emits], and Events\Event implements the rest with its defaults. */
interface Event
{
    /** @return array<string, mixed> */
    public function payload(): array;

    /** @return list<string> the only modules that handle it; empty for every module */
    public function recipients(): array;

    /** The stream of distributable.events.streams it travels on; null for the default one. */
    public function stream(): ?string;
}
