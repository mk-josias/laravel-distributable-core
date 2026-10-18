<?php

declare(strict_types=1);

namespace Distributable\Providers;

use Distributable\Contracts\Stream\Versioned;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Rpc\RpcService;
use Distributable\Services\Rpc\RpcServices;
use Distributable\Services\Stream\PayloadVersions;
use Illuminate\Support\ServiceProvider;

/**
 * Base of the application's {Foundation}\FoundationServiceProvider: each contract of $rpc is bound
 * to the RpcService that calls its module, found from the foundation folder the contract is in.
 * A module answers its own contracts in its provider's $services.
 */
abstract class FoundationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string<RpcService>> each contract the modules call, with its RpcService */
    protected array $rpc = [];

    /** @var array<string, class-string<Versioned>> event name => the payload class that declares its versions */
    protected array $payloads = [];

    public function boot(): void
    {
        $this->app->make(PayloadVersions::class)->add($this->payloads);

        $registry = $this->app->make(ModuleRegistry::class);
        $services = [];

        foreach ($this->rpc as $contract => $service) {
            $this->app->bind($contract, $service);

            $services[$contract] = ['service' => $registry->ownerOf($contract)?->name, 'rpc' => $service];
        }

        $this->app->make(RpcServices::class)->add($services);
    }
}
