<?php

declare(strict_types=1);

namespace Distributable\Services\Rpc;

use Distributable\Contracts\Rpc\Transport;
use Distributable\Services\ModuleRegistry;
use Illuminate\Container\Container;

/** A message to a module this process runs is answered directly; any other goes out on the module's transport. */
final readonly class ModuleRpcTransport implements Transport
{
    public function __construct(
        private MessageRegistry $messages,
        private TransportManager $remote,
    ) {}

    public function invoke(string $module, string $name, int $version, array $payload): mixed
    {
        // From the running application: Octane serves each request from its own copy.
        $registry = Container::getInstance()->make(ModuleRegistry::class);

        return $registry->find($module) !== null && $registry->isLocal($module)
            ? $this->messages->answer($module, $name, $version, $payload)
            : $this->remote->invoke($module, $name, $version, $payload);
    }
}
