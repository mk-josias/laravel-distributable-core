<?php

declare(strict_types=1);

namespace Distributable\Providers;

use Distributable\Config\Services;
use Distributable\Contracts\Colocation;
use Distributable\Contracts\Stream\Versioned;
use Distributable\Services\Rpc\LocalServices;
use Distributable\Services\Rpc\RpcService;
use Distributable\Services\Rpc\RpcServices;
use Distributable\Services\Stream\PayloadVersions;
use Illuminate\Support\ServiceProvider;

/**
 * Base of the provider that wires an application's contracts: the ones it calls, each bound to
 * the RpcService that reaches its service, and the ones it answers, each with its implementation.
 */
abstract class RpcServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string<RpcService>> each contract this application calls, with its RpcService */
    protected array $rpc = [];

    /** @var array<class-string, class-string> each contract this application answers, with its implementation */
    protected array $services = [];

    /** @var array<string, class-string<Versioned>> event name => the payload class that declares its versions */
    protected array $payloads = [];

    public function boot(): void
    {
        $name = $this->app->make(Services::class)->getName();

        foreach ($this->services as $contract => $implementation) {
            if ($name !== null) {
                $this->app->make(LocalServices::class)->add($name, $contract, $implementation);
            }

            $this->app->bind($contract, $implementation);
        }

        $this->app->make(PayloadVersions::class)->add($this->payloads);

        $colocation = $this->app->make(Colocation::class);
        $services = [];

        foreach ($this->rpc as $contract => $service) {
            $this->app->bind($contract, $service);

            $services[$contract] = ['service' => $colocation->serviceOf($contract), 'rpc' => $service];
        }

        $this->app->make(RpcServices::class)->add($services);
    }
}
