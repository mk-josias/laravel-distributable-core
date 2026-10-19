<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Console\ArchiveOptions;
use Distributable\Services\Stream\Archiver;
use Illuminate\Console\Command;

/** Publishes the entries of an export back on a stream, e.g. to rebuild one that was emptied. */
final class ImportEvents extends Command
{
    use ArchiveOptions;

    protected $signature = 'stream:import
        {source : What stream:export wrote, e.g. its JSON-lines file for `file`}
        {--store= : The archive store (default: distributable.events.archive)}
        {--emitter=* : Only entries these modules emitted}
        {--stream= : The stream to publish on (default: distributable.events.stream)}
        {--since= : Only entries emitted on or after this date}
        {--until= : Only entries emitted before this date}
        {--where=* : Only entries matching name=value, emitter=value, or payload.field=value}';

    protected $description = 'Publish the entries of an export back on a stream.';

    public function handle(Archiver $archiver): int
    {
        $count = $archiver->import(
            store: $this->archiveStore(),
            source: (string) $this->argument('source'),
            stream: $this->stringOption('stream'),
            emitters: array_values(array_map(strval(...), (array) $this->option('emitter'))),
            since: $this->stringOption('since'),
            until: $this->stringOption('until'),
            where: $this->whereOption(),
        );

        $this->line("→ {$count} imported");

        return self::SUCCESS;
    }
}
