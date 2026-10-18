<?php

declare(strict_types=1);

namespace Distributable\Services\Shadows;

use Distributable\Contracts\Shadows\Shadowed;
use Distributable\Models\ShadowModel;
use Distributable\Services\Discovery\ClassDiscovery;
use Distributable\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;

/** Finds, among the services this process runs, the copies each one declares and the source models it owns. */
class ShadowRegistry
{
    public function __construct(protected readonly ModuleRegistry $registry) {}

    /** @return list<class-string<ShadowModel>> the copies of $sourceTable kept here, or by $keeper alone */
    public function shadowsOf(string $sourceTable, ?string $keeper = null): array
    {
        return array_values(array_filter(
            $this->localShadows($keeper),
            static fn (string $shadow): bool => $shadow::sourceTable() === $sourceTable,
        ));
    }

    /** @return list<class-string<ShadowModel>> every copy the local services keep, or $keeper alone */
    public function localShadows(?string $keeper = null): array
    {
        $shadows = [];

        foreach ($keeper === null ? $this->registry->localNames() : [$keeper] as $service) {
            $shadows = [...$shadows, ...$this->kept($service)];
        }

        return $shadows;
    }

    /** @return list<string> the local services keeping a copy of $sourceTable */
    public function keepersOf(string $sourceTable): array
    {
        return array_values(array_filter(
            $this->registry->localNames(),
            fn (string $service): bool => $this->shadowsOf($sourceTable, $service) !== [],
        ));
    }

    /** The local source model of $sourceTable, if this process runs its owner (or $owner is it). */
    public function sourceOf(string $sourceTable, ?string $owner = null): ?Shadowed
    {
        foreach ($owner === null ? $this->registry->localNames() : [$owner] as $service) {
            foreach ($this->sources($service) as $class) {
                /** @var Model&Shadowed $model */
                $model = new $class;

                if ($model->getTable() === $sourceTable) {
                    return $model;
                }
            }
        }

        return null;
    }

    /** @return list<class-string> */
    public function scanSources(string $service): array
    {
        return ClassDiscovery::with(Shadowed::class, $this->registry->get($service)->classPath());
    }

    /** @return list<class-string<ShadowModel>> the copies a module's provider lists in $shadows */
    protected function kept(string $module): array
    {
        /** @var list<class-string<ShadowModel>> */
        return array_values((array) config("distributable.shadows.{$module}", []));
    }

    /** @return list<class-string> */
    protected function sources(string $service): array
    {
        return $this->scanSources($service);
    }
}
