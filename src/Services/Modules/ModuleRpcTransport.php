<?php

declare(strict_types=1);

namespace Distributable\Services\Modules;

use Distributable\Contracts\Rpc\Transport;
use Distributable\Services\Rpc\LocalServices;
use Distributable\Services\Rpc\TransportManager;
use Illuminate\Container\Container;

/** A call to a module this process runs is a direct method call; any other goes out on the module's transport. */
final readonly class ModuleRpcTransport implements Transport
{
    public function __construct(
        private LocalServices $services,
        private TransportManager $remote,
    ) {}

    public function invoke(string $service, string $contract, string $method, array $arguments = []): mixed
    {
        // From the running application: Octane serves each request from its own copy.
        $registry = Container::getInstance()->make(ModuleRegistry::class);

        return $registry->find($service) !== null && $registry->isLocal($service)
            ? $this->services->call($service, $contract, $method, $arguments)
            : $this->remote->invoke($service, $contract, $method, $arguments);
    }
}
