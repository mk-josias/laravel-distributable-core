<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Config\Streamer;
use Distributable\Context\ModuleContext;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Stream\Outbox\Relay;
use Illuminate\Console\Command;

/**
 * Sends the events still stored in the database: those that could not go out right after their
 * commit. The consumption guard turns what a consumer already applied into a no-op, so sending
 * twice costs time and changes nothing.
 */
final class RepublishEvents extends Command
{
    protected $signature = 'stream:republish';

    protected $description = 'Send the events stored in the database that could not be sent after their commit.';

    public function handle(Relay $relay, Streamer $config, ModuleRegistry $registry, ModuleContext $context): int
    {
        if (! $config->publishesReliably()) {
            $this->error('Events are not published reliably: set DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH=true first.');

            return self::FAILURE;
        }

        $current = $context->current()?->name;

        foreach ($current !== null ? [$current] : $registry->localNames() as $module) {
            $this->line("→ {$module}: {$relay->drain($module)} sent");
        }

        return self::SUCCESS;
    }
}
