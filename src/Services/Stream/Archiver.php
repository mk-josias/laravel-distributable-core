<?php

declare(strict_types=1);

namespace Distributable\Services\Stream;

use Carbon\CarbonImmutable;
use Distributable\Config\Streamer;
use Distributable\Contracts\Stream\ArchivesStreams;
use Distributable\Contracts\Stream\ArchiveStore;
use Distributable\Data\Envelope;
use Distributable\Exceptions\ConfigurationException;

/** Copies the entries of a stream to an archive store, and puts them back on a stream. */
final class Archiver
{
    public function __construct(private readonly TransportManager $transports, private readonly Streamer $streams) {}

    /**
     * Writes the matching entries of the stream to $target, in batches.
     *
     * @param  list<string>  $emitters  the modules whose events to take; empty for all of them
     * @param  array<string, string>  $where  name, emitter, or payload.{field}, => value
     */
    public function export(
        ArchiveStore $store,
        string $target,
        ?string $stream = null,
        array $emitters = [],
        ?string $since = null,
        ?string $until = null,
        array $where = [],
        bool $acknowledged = false,
        int $batch = 1000,
    ): int {
        self::assertFilters($where);

        $stream ??= $this->streams->getDefaultStream();
        $transport = $this->transports->stream($stream);

        if (! $transport instanceof ArchivesStreams) {
            throw ConfigurationException::unarchivableStream($stream);
        }

        $exported = 0;
        $rows = [];

        foreach ($transport->entries($this->date($since), $this->date($until), $acknowledged) as $envelope) {
            if (! $this->matches($envelope, $emitters, $where)) {
                continue;
            }

            $rows[] = $envelope->toArray();

            if (count($rows) >= $batch) {
                $store->write($target, $rows);
                $exported += count($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $store->write($target, $rows);
            $exported += count($rows);
        }

        return $exported;
    }

    /**
     * Publishes the matching entries of $source on the stream, in the order they were written.
     *
     * @param  list<string>  $emitters  the modules whose events to take; empty for all of them
     * @param  array<string, string>  $where  name, emitter, or payload.{field}, => value
     */
    public function import(
        ArchiveStore $store,
        string $source,
        ?string $stream = null,
        array $emitters = [],
        ?string $since = null,
        ?string $until = null,
        array $where = [],
    ): int {
        self::assertFilters($where);

        $transport = $this->transports->stream($stream);
        $from = $this->date($since);
        $to = $this->date($until);
        $imported = 0;

        foreach ($store->read($source) as $row) {
            $envelope = Envelope::fromArray($row);

            if (($from !== null && $envelope->emittedAt < $from)
                || ($to !== null && $envelope->emittedAt >= $to)
                || ! $this->matches($envelope, $emitters, $where)) {
                continue;
            }

            $transport->publish($envelope);
            $imported++;
        }

        return $imported;
    }

    /**
     * @param  list<string>  $emitters
     * @param  array<string, string>  $where
     */
    private function matches(Envelope $envelope, array $emitters, array $where): bool
    {
        if ($emitters !== [] && ! in_array($envelope->emitter, $emitters, true)) {
            return false;
        }

        foreach ($where as $column => $value) {
            $field = str_starts_with($column, 'payload.')
                ? data_get($envelope->payload, substr($column, strlen('payload.')))
                : ($column === 'name' ? $envelope->name : $envelope->emitter);

            if ((string) $field !== $value) {
                return false;
            }
        }

        return true;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /** @param array<string, string> $where */
    private static function assertFilters(array $where): void
    {
        foreach (array_keys($where) as $column) {
            if (preg_match('/^(name|emitter|payload(\.\w+)+)$/', $column) !== 1) {
                throw ConfigurationException::invalidExportFilter($column);
            }
        }
    }
}
