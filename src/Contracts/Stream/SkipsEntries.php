<?php

declare(strict_types=1);

namespace Distributable\Contracts\Stream;

use Carbon\CarbonImmutable;
use Distributable\Data\Envelope;

/** A transport that can drop entries of a consumer without running their handlers. */
interface SkipsEntries
{
    /** Acknowledges the consumer's oldest pending entry; its envelope, or null when none is pending. */
    public function skipOldest(string $consumer): ?Envelope;

    /** Drops every entry of the consumer up to $until, read or not; how many it dropped. */
    public function skipUntil(string $consumer, CarbonImmutable $until): int;
}
