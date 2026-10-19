<?php

declare(strict_types=1);

namespace Distributable\Services\Shadows;

use Distributable\Services\Discovery\DiscoveryCache;
use Distributable\Services\ModuleRegistry;

/** The shadow sources of each module, read from modules:cache when it ran, scanned otherwise; copies come from each module's $shadows. */
final class CachedShadowRegistry extends ShadowRegistry
{
    public function __construct(ModuleRegistry $registry, private readonly DiscoveryCache $cache)
    {
        parent::__construct($registry);
    }

    /** @return list<class-string> */
    protected function sources(string $module): array
    {
        return $this->cache->load()['sources'][$module] ?? parent::sources($module);
    }
}
