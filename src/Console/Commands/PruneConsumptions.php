<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Config\Streamer;
use Distributable\Context\ModuleContext;
use Distributable\Services\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

/** A consumption mark only matters while its event can still be redelivered: past the stream's retention it guards nothing. */
final class PruneConsumptions extends Command
{
    protected $signature = 'stream:prune-consumptions
        {--days=7 : Keep the marks of the last N days; must outlast how long an event can stay in the stream}';

    protected $description = 'Delete the consumption marks older than the given number of days, in every local module.';

    public function handle(ModuleRegistry $registry, ModuleContext $context, Streamer $config): int
    {
        $table = $config->getConsumptionsTable();
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $before = Date::now()->subDays($days);

        $current = $context->current()?->name;

        foreach ($current !== null ? [$current] : $registry->localNames() as $module) {
            try {
                $deleted = $context->within($registry->get($module), static fn (): int => DB::table($table)->where('consumed_at', '<', $before)->delete());
                $this->line("→ {$module}: {$deleted} deleted");
            } catch (Throwable $e) {
                $this->warn("→ {$module}: skipped ({$e->getMessage()})");
            }
        }

        return self::SUCCESS;
    }
}
