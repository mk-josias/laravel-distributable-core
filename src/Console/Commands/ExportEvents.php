<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Console\ArchiveOptions;
use Distributable\Services\Stream\Archiver;
use Illuminate\Console\Command;

/** Copies what the stream keeps to an archive. Nothing is deleted: stream:trim drops what every consumer has read. */
final class ExportEvents extends Command
{
    use ArchiveOptions;

    protected $signature = 'stream:export
        {target : Where the store appends the entries, e.g. a JSON-lines file for `file`}
        {--store= : The archive store (default: microservices.events.archive)}
        {--service=* : Only entries these services emitted}
        {--stream= : The stream to read (default: microservices.events.stream)}
        {--since= : Only entries on or after this date}
        {--until= : Only entries before this date}
        {--where=* : Only entries matching name=value, emitter=value, or payload.field=value}
        {--acknowledged : Only entries every consumer has acknowledged}
        {--batch=1000 : Entries written per batch}';

    protected $description = 'Copy the entries of a stream to an archive.';

    public function handle(Archiver $archiver): int
    {
        $count = $archiver->export(
            store: $this->archiveStore(),
            target: (string) $this->argument('target'),
            stream: $this->stringOption('stream'),
            services: array_values(array_map(strval(...), (array) $this->option('service'))),
            since: $this->stringOption('since'),
            until: $this->stringOption('until'),
            where: $this->whereOption(),
            acknowledged: (bool) $this->option('acknowledged'),
            batch: max(1, (int) $this->option('batch')),
        );

        $this->line("→ {$count} exported");

        return self::SUCCESS;
    }
}
