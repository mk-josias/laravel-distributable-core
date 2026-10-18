<?php

declare(strict_types=1);

namespace Distributable\Providers;

use Distributable\Console\Commands\AnnounceShadows;
use Distributable\Console\Commands\ConsumeEvents;
use Distributable\Console\Commands\ExportEvents;
use Distributable\Console\Commands\ImportEvents;
use Distributable\Console\Commands\MakeFoundation;
use Distributable\Console\Commands\PruneConsumptions;
use Distributable\Console\Commands\RepublishEvents;
use Distributable\Console\Commands\SkipEntries;
use Distributable\Console\Commands\TrimEvents;
use Distributable\Console\Commands\WantShadows;
use Distributable\Contracts\Colocation;
use Distributable\Contracts\Rpc\Transport as RpcTransport;
use Distributable\Contracts\Stream\Bus;
use Distributable\Contracts\Stream\Transport;
use Distributable\Services\Rpc\LocalServices;
use Distributable\Services\Rpc\RpcServices;
use Distributable\Services\Rpc\TransportManager as RpcTransportManager;
use Distributable\Services\Shadows\ShadowRegistry;
use Distributable\Services\SingleService;
use Distributable\Services\Stream\ArchiveStores;
use Distributable\Services\Stream\Emitter;
use Distributable\Services\Stream\PayloadVersions;
use Distributable\Services\Stream\TransportManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class MicroservicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/microservices.php', 'microservices');
        $this->mergeSections(['rpc', 'events']);

        // If: a package running several services in one process binds its own, whichever provider registers first.
        $this->app->bindIf(Colocation::class, SingleService::class);
        $this->app->singletonIf(ShadowRegistry::class);
        $this->app->bindIf(RpcTransport::class, RpcTransportManager::class);

        $this->app->singleton(TransportManager::class);
        $this->app->singleton(ArchiveStores::class);
        $this->app->singleton(PayloadVersions::class);
        $this->app->singleton(RpcTransportManager::class);
        $this->app->singleton(RpcServices::class);
        $this->app->singleton(LocalServices::class);

        $this->app->singleton(Bus::class, Emitter::class);

        // Injecting the contract yields the default stream; the manager stays the seam
        // where a consumer registers its own driver with extend().
        $this->app->bind(
            Transport::class,
            static fn (Application $app): Transport => $app->make(TransportManager::class)->stream(),
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/microservices.php' => config_path('microservices.php'),
        ], 'microservices-config');

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RepublishEvents::class, ConsumeEvents::class, SkipEntries::class, TrimEvents::class, PruneConsumptions::class,
                ExportEvents::class, ImportEvents::class, AnnounceShadows::class, WantShadows::class, MakeFoundation::class,
            ]);
        }
    }

    /**
     * An application's `'events' => ['listen' => …]` keeps the shipped streams and tables.
     *
     * @param  list<string>  $sections
     */
    private function mergeSections(array $sections): void
    {
        if ($this->app instanceof \Illuminate\Foundation\Application && $this->app->configurationIsCached()) {
            return;
        }

        $defaults = require __DIR__.'/../../config/microservices.php';
        $config = $this->app->make('config');

        foreach ($sections as $section) {
            $config->set("microservices.{$section}", array_replace($defaults[$section], (array) $config->get("microservices.{$section}", [])));
        }
    }
}
