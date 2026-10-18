<?php

declare(strict_types=1);

namespace Distributable\Contracts\Stream;

use Carbon\CarbonImmutable;
use Distributable\Data\Envelope;

/** A transport that keeps envelopes after delivery, and reads them back for an archive. */
interface ArchivesStreams
{
    /**
     * The entries of the stream between the two dates, oldest first. With $acknowledgedOnly, only those
     * every consumer has acknowledged.
     *
     * @return iterable<Envelope>
     */
    public function entries(?CarbonImmutable $since, ?CarbonImmutable $until, bool $acknowledgedOnly): iterable;
}
