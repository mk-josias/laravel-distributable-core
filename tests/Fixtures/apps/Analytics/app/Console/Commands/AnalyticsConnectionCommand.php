<?php

declare(strict_types=1);

namespace Apps\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** A command analytics declares: it prints the connection it runs on. */
final class AnalyticsConnectionCommand extends Command
{
    protected $signature = 'analytics:connection';

    public function handle(): int
    {
        $this->line(DB::getDefaultConnection());

        return self::SUCCESS;
    }
}
