<?php

declare(strict_types=1);

namespace Distributable\Services\Rpc;

use Closure;
use Distributable\Config\Rpc;
use Distributable\Contracts\Rpc\Transport;
use Distributable\Exceptions\ConfigurationException;
use Illuminate\Contracts\Container\Container;

/**
 * Routes each call to the named transport of distributable.rpc.transports its module's host
 * names. extend('grpc', fn ($app, array $config, string $name) => …) registers any driver.
 */
final class TransportManager implements Transport
{
    /** @var array<string, Closure(Container, array<string, mixed>, string): Transport> */
    private array $creators = [];

    /** @var array<string, Transport> */
    private array $transports = [];

    public function __construct(private readonly Container $container) {}

    public function invoke(string $module, string $name, int $version, array $payload): mixed
    {
        return $this->transport($this->config()->getTransportOf($module))->invoke($module, $name, $version, $payload);
    }

    public function transport(?string $name = null): Transport
    {
        $name ??= $this->config()->getDefaultTransport();

        return $this->transports[$name] ??= $this->create($name, $this->config()->getTransport($name));
    }

    /** From the running application: Octane serves each request from its own copy. */
    private function config(): Rpc
    {
        return \Illuminate\Container\Container::getInstance()->make(Rpc::class);
    }

    /** @param Closure(Container, array<string, mixed>, string): Transport $creator */
    public function extend(string $driver, Closure $creator): static
    {
        $this->creators[$driver] = $creator;
        $this->transports = [];

        return $this;
    }

    /** @param array<string, mixed> $config */
    private function create(string $name, array $config): Transport
    {
        $driver = (string) ($config['driver'] ?? '');

        if (! isset($this->creators[$driver])) {
            throw ConfigurationException::unknownRpcDriver($driver, $name);
        }

        return ($this->creators[$driver])($this->container, $config, $name);
    }
}
