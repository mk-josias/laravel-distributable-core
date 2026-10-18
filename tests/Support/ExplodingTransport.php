<?php

declare(strict_types=1);

namespace Distributable\Tests\Support;

use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;
use RuntimeException;

/** A broker that is down. */
final class ExplodingTransport implements Transport
{
    public function publish(Envelope $envelope): void
    {
        throw new RuntimeException('transport down');
    }

    public function consume(array $consumers, array $channels, callable $handle): void {}

    public function stop(): void {}
}
