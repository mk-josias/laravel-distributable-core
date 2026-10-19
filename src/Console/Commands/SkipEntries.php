<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Carbon\CarbonImmutable;
use Distributable\Context\ModuleContext;
use Distributable\Contracts\Stream\SkipsEntries;
use Distributable\Services\Stream\TransportManager;
use Illuminate\Console\Command;

final class SkipEntries extends Command
{
    protected $signature = 'stream:skip
        {--stream= : The stream to read (default: distributable.events.stream)}
        {--until= : Drop every entry up to this date, read or not (default: only the oldest failing one)}';

    protected $description = 'Drop failing entries of a consumer without running their handlers, so a blocked stream goes on.';

    public function handle(ModuleContext $context, TransportManager $transports): int
    {
        $transport = $transports->stream($this->option('stream') !== null ? (string) $this->option('stream') : null);
        $module = (string) $context->current()?->name;

        if (! $transport instanceof SkipsEntries || $module === '') {
            $this->error('Name the consuming module with --module, on a transport that can skip entries.');

            return self::FAILURE;
        }

        if ($this->option('until') !== null) {
            $this->line('→ skipped '.$transport->skipUntil($module, CarbonImmutable::parse((string) $this->option('until'))).' entries for ['.$module.']');

            return self::SUCCESS;
        }

        $envelope = $transport->skipOldest($module);

        $this->line($envelope === null ? 'Nothing pending for ['.$module.'].' : '→ skipped ['.$envelope->name.'] '.$envelope->id.' for ['.$module.']');

        return self::SUCCESS;
    }
}
