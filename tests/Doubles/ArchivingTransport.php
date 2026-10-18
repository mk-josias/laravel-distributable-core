<?php

declare(strict_types=1);

namespace Distributable\Tests\Doubles;

use Carbon\CarbonImmutable;
use Distributable\Contracts\Stream\ArchivesStreams;
use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;

/** A stream that keeps what it is given, and has no consumer: the first $acknowledged entries count as acknowledged. */
final class ArchivingTransport implements ArchivesStreams, Transport
{
    public int $acknowledged = PHP_INT_MAX;

    /** @var list<Envelope> */
    public array $published = [];

    public function publish(Envelope $envelope): void
    {
        $this->published[] = $envelope;
    }

    public function consume(array $consumers, array $channels, callable $handle): void {}

    public function stop(): void {}

    public function entries(?CarbonImmutable $since, ?CarbonImmutable $until, bool $acknowledgedOnly): iterable
    {
        $entries = $acknowledgedOnly ? array_slice($this->published, 0, $this->acknowledged) : $this->published;

        foreach ($entries as $envelope) {
            if (($since === null || $envelope->emittedAt >= $since) && ($until === null || $envelope->emittedAt < $until)) {
                yield $envelope;
            }
        }
    }
}
