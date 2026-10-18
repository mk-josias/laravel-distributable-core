<?php

declare(strict_types=1);

namespace Distributable\Services\Shadows;

use Distributable\Contracts\Colocation;
use Distributable\Services\Discovery\DiscoveryCache;

/** The shadow sources of each module, read from modules:cache when it ran, scanned otherwise; copies come from each module's $shadows. */
final class CachedShadowRegistry extends ShadowRegistry
{
    public function __construct(Colocation $colocation, private readonly DiscoveryCache $cache)
    {
        parent::__construct($colocation);
    }

    /** @return list<class-string> */
    protected function sources(string $service): array
    {
        return $this->cache->load()['sources'][$service] ?? parent::sources($service);
    }
}
