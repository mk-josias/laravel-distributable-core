<?php

declare(strict_types=1);

namespace Distributable\Services\Stream;

use Distributable\Config\Streamer;
use Distributable\Contracts\Stream\Bus;
use Distributable\Contracts\Stream\Event;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Stream\Outbox\Relay;
use Distributable\Services\Stream\Outbox\Writer;
use Illuminate\Database\DatabaseManager;

/**
 * The single emission path: an event becomes an envelope stamped with its emitting module, then
 * goes onto its stream right after the commit of the transaction it was emitted in. With
 * reliable_publish, its row is first written in that transaction and stays pending if the sending fails.
 */
final class Emitter implements Bus
{
    public function __construct(
        private readonly EnvelopeFactory $envelopes,
        private readonly TransportManager $transports,
        private readonly Writer $outbox,
        private readonly Relay $relay,
        private readonly DatabaseManager $db,
        private readonly ModuleRegistry $registry,
        private readonly Streamer $config,
    ) {}

    public function emit(Event $event, ?string $emitter = null): void
    {
        $envelope = $this->envelopes->for($event, $emitter);

        $connection = $this->db->connection($this->registry->connectionOf($envelope->emitter));

        if (! $this->config->publishesReliably()) {
            $connection->afterCommit(fn () => $this->transports->stream($envelope->stream)->publish($envelope));

            return;
        }

        $this->outbox->write($envelope);

        $connection->afterCommit(fn () => $this->relay->publishNow($envelope->emitter, $envelope->id));
    }
}
