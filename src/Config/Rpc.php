<?php

declare(strict_types=1);

namespace Distributable\Config;

use Distributable\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository;

/** The distributable.rpc.* settings, and the host of each module; read live, never snapshotted. */
final readonly class Rpc
{
    public function __construct(private Repository $config) {}

    public function hasHost(string $module): bool
    {
        return $this->config->get("distributable.declared.{$module}.host") !== null;
    }

    public function getHost(string $module): string
    {
        $host = $this->host($module);

        return (string) (is_array($host) ? ($host['url'] ?? '') : $host);
    }

    /** The transport a module's calls travel on: its host's `transport`, or distributable.rpc.transport. */
    public function getTransportOf(string $module): string
    {
        $host = $this->host($module);

        return (string) (is_array($host) && isset($host['transport']) ? $host['transport'] : $this->getDefaultTransport());
    }

    public function getDefaultTransport(): string
    {
        $name = $this->config->get('distributable.rpc.transport');

        return is_string($name) && $name !== '' ? $name : throw ConfigurationException::noRpcTransport();
    }

    /** @return array<string, mixed> */
    public function getTransport(string $name): array
    {
        /** @var array<string, array<string, mixed>> $transports */
        $transports = (array) $this->config->get('distributable.rpc.transports', []);

        return $transports[$name] ?? throw ConfigurationException::unknownRpcTransport($name);
    }

    /** @return string|array<string, mixed> */
    private function host(string $module): string|array
    {
        /** @var string|array<string, mixed>|null $host */
        $host = $this->config->get("distributable.declared.{$module}.host");

        return $host ?? throw ConfigurationException::missingRpcHost($module);
    }

    /** Null is the application's default cache store. */
    public function getCacheStore(): ?string
    {
        $store = $this->config->get('distributable.rpc.cache');

        return is_string($store) && $store !== '' ? $store : null;
    }
}
