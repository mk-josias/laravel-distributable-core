<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Services\Discovery\DiscoveryCache;
use Illuminate\Console\Command;

final class ClearModules extends Command
{
    protected $signature = 'modules:clear';

    protected $description = 'Remove the modules cache file.';

    public function handle(DiscoveryCache $cache): int
    {
        $cache->clear();

        $this->components->info('Modules cache cleared.');

        return self::SUCCESS;
    }
}
