<?php

declare(strict_types=1);

namespace Distributable\Config;

use Distributable\Data\Module;
use Illuminate\Contracts\Config\Repository;

/** The modules.* settings, typed and defaulted; read live, never snapshotted. */
final readonly class Modules
{
    public function __construct(private Repository $config) {}

    /** @return list<string> the names modules.declared declares */
    public function getDeclaredModules(): array
    {
        return array_map(strval(...), array_keys((array) $this->config->get('modules.declared', [])));
    }

    /** The module declared under $name, its folders read from modules.declared.{name}.module and .foundation. */
    public function module(string $name): Module
    {
        return Module::fromName($name, $this->getModulesNamespace(), $this->getModulesPath(), $this->declared($name, 'foundation'), $this->declared($name, 'module'));
    }

    private function declared(string $name, string $key): ?string
    {
        $value = $this->config->get("modules.declared.{$name}.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The URL a module answers on when it runs in another process; null when none is set. */
    public function getHost(string $module): ?string
    {
        $host = $this->config->get("modules.declared.{$module}.host");

        return is_array($host) ? (is_string($host['url'] ?? null) ? $host['url'] : null) : (is_string($host) ? $host : null);
    }

    /** @return list<string> ['*'] for every module, or the names RUN_MODULES lists */
    public function getLoadedModules(): array
    {
        $names = array_map(trim(...), explode(',', (string) $this->config->get('modules.runs', '*')));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }

    public function getModulesPath(): string
    {
        return (string) $this->config->get('modules.paths.modules', 'apps');
    }

    public function getModulesNamespace(): string
    {
        return (string) $this->config->get('modules.namespaces.modules', 'Apps');
    }

    /** Absolute; the foundation directory, or the one of a module (foundation/Iam) when given. */
    public function getFoundationPath(?Module $module = null): string
    {
        $path = (string) $this->config->get('modules.paths.foundation', 'foundation');
        $root = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        return $module === null ? $root : $root.'/'.$module->foundation;
    }

    public function getFoundationNamespace(): string
    {
        return (string) $this->config->get('modules.namespaces.foundation', 'Foundation');
    }

    public function getStatusRoute(): ?string
    {
        $route = $this->config->get('modules.status_route');

        return is_string($route) && $route !== '' ? $route : null;
    }
}
