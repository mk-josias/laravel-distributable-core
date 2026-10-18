<?php

declare(strict_types=1);

namespace Distributable\Tests\Support;

use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;

/** A consumer-supplied transport, registered through TransportManager::extend(). */
final class RecordingTransport implements Transport
{
    /** @var list<Envelope> */
    public array $published = [];

    public function publish(Envelope $envelope): void
    {
        $this->published[] = $envelope;
    }

    public function consume(array $consumers, array $channels, callable $handle): void {}

    public function stop(): void {}
}
