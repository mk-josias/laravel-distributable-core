<?php

declare(strict_types=1);

namespace Distributable\Events;

use Distributable\Attributes\Emits;

/** A module keeping a copy asks the owner of the source table to announce every row, to it alone. */
#[Emits(ShadowWanted::NAME)]
final class ShadowWanted extends Event
{
    public const string NAME = 'distributable.shadow.wanted';

    public function __construct(
        private readonly string $keeper,
        private readonly string $owner,
        private readonly string $source,
    ) {}

    /** @return array{source: string, keeper: string} */
    public function payload(): array
    {
        return ['source' => $this->source, 'keeper' => $this->keeper];
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return [$this->owner];
    }
}
