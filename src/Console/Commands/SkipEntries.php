<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Carbon\CarbonImmutable;
use Distributable\Contracts\Colocation;
use Distributable\Contracts\Stream\SkipsEntries;
use Distributable\Services\Stream\TransportManager;
use Illuminate\Console\Command;

final class SkipEntries extends Command
{
    protected $signature = 'stream:skip
        {--service= : The consuming service (default: the one running)}
        {--stream= : The stream to read (default: microservices.events.stream)}
        {--until= : Drop every entry up to this date, read or not (default: only the oldest failing one)}';

    protected $description = 'Drop failing entries of a consumer without running their handlers, so a blocked stream goes on.';

    public function handle(Colocation $colocation, TransportManager $transports): int
    {
        $transport = $transports->stream($this->option('stream') !== null ? (string) $this->option('stream') : null);
        $service = (string) ($this->option('service') ?: $colocation->current());

        if (! $transport instanceof SkipsEntries || $service === '') {
            $this->error('Name the consuming service with --service, on a transport that can skip entries.');

            return self::FAILURE;
        }

        if ($this->option('until') !== null) {
            $this->line('→ skipped '.$transport->skipUntil($service, CarbonImmutable::parse((string) $this->option('until'))).' entries for ['.$service.']');

            return self::SUCCESS;
        }

        $envelope = $transport->skipOldest($service);

        $this->line($envelope === null ? 'Nothing pending for ['.$service.'].' : '→ skipped ['.$envelope->name.'] '.$envelope->id.' for ['.$service.']');

        return self::SUCCESS;
    }
}
