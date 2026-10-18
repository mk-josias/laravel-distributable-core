<?php

declare(strict_types=1);

namespace Distributable\Transports\Stream;

use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;

/** Drops every envelope — for tests, and for running a node with its reactions cut. */
final class NullTransport implements Transport
{
    public function publish(Envelope $envelope): void {}

    public function consume(array $consumers, array $channels, callable $handle): void {}

    public function stop(): void {}
}
