<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Contracts\Stream\Bus;
use Distributable\Events\ShadowWanted;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Shadows\ShadowRegistry;
use Illuminate\Console\Command;

/** Run by a service keeping copies: asks the owner of each source table to announce what it holds. */
final class WantShadows extends Command
{
    protected $signature = 'shadows:want
        {--keepers=* : Only these services ask for their copies (default: every local keeper)}
        {--sources=* : Only these source tables (default: every one they copy)}';

    protected $description = 'Ask the owners of the source tables copied here to announce their rows.';

    public function handle(ShadowRegistry $catalog, ModuleRegistry $registry, Bus $bus): int
    {
        $keepers = (array) $this->option('keepers');
        $sources = (array) $this->option('sources');

        foreach ($registry->localNames() as $keeper) {
            if ($keepers !== [] && ! in_array($keeper, $keepers, true)) {
                continue;
            }

            foreach (array_unique(array_map(static fn (string $shadow): string => $shadow::sourceTable(), $catalog->localShadows($keeper))) as $source) {
                if ($sources !== [] && ! in_array($source, $sources, true)) {
                    continue;
                }

                $owner = $catalog->shadowsOf($source, $keeper)[0]::owner();
                $bus->emit(new ShadowWanted($keeper, $owner, $source));
                $this->line("→ {$keeper} wants {$source}");
            }
        }

        return self::SUCCESS;
    }
}
