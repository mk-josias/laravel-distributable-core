<?php

declare(strict_types=1);

namespace Distributable\Services\Stream\Outbox;

use Distributable\Config\Streamer;
use Distributable\Data\Envelope;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Stream\TransportManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Puts a service's stored events on the wire: the row just committed, or every row still there.
 * A row is deleted once it is sent; one that fails to go out stays, with its attempts and last error.
 *
 * Delivery is at-least-once — a crash between sending and deleting sends again. That is why
 * the consumption guard exists.
 */
final class Relay
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TransportManager $transports,
        private readonly ModuleRegistry $registry,
        private readonly Streamer $config,
    ) {}

    /** Sends the row just committed; a failure leaves it stored, and never reaches the caller. */
    public function publishNow(string $service, string $id): void
    {
        try {
            $row = $this->table($service)->where('id', $id)->first();

            if ($row !== null) {
                $this->publish($service, $row);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Sends every row of the service, in batches. A row that fails stays and the pass goes on.
     * Only the service's own rows: services sharing a database share the table.
     *
     * @return int the number of rows sent
     */
    public function drain(string $service, int $batch = 100): int
    {
        $sent = 0;
        $after = 0;

        do {
            $rows = $this->table($service)
                ->where('emitter', $service)
                ->where('sequence', '>', $after)
                ->orderBy('sequence')
                ->limit($batch)
                ->get();

            foreach ($rows as $row) {
                $after = (int) $row->sequence;

                if ($this->publish($service, $row)) {
                    $sent++;
                }
            }
        } while ($rows->count() === $batch);

        return $sent;
    }

    private function publish(string $service, stdClass $row): bool
    {
        try {
            $envelope = $this->envelope($row);

            $this->transports->stream($envelope->stream)->publish($envelope);
        } catch (Throwable $e) {
            $this->table($service)->where('sequence', $row->sequence)->update([
                'attempts' => (int) $row->attempts + 1,
                'last_error' => Str::limit($e->getMessage(), 1000),
            ]);

            return false;
        }

        $this->table($service)->where('sequence', $row->sequence)->delete();

        return true;
    }

    private function envelope(stdClass $row): Envelope
    {
        return Envelope::fromArray([
            'id' => $row->id,
            'emitter' => $row->emitter,
            'name' => $row->name,
            'payload' => json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
            'headers' => json_decode((string) $row->headers, true, 512, JSON_THROW_ON_ERROR),
            'emitted_at' => $row->emitted_at,
            'recipients' => json_decode((string) $row->recipients, true, 512, JSON_THROW_ON_ERROR),
            'stream' => $row->stream,
            'version' => $row->version,
        ]);
    }

    private function table(string $service): Builder
    {
        return $this->db->connection($this->registry->connectionOf($service))->table($this->config->getPublicationsTable());
    }
}
