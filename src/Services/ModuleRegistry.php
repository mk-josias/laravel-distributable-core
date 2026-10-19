<?php

declare(strict_types=1);

namespace Distributable\Services;

use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;

/**
 * The process-level view of the modules: every module distributable.declared lists, and the ones
 * this process loads (RUN_MODULES). Being loaded is a fact about the process, never about a module.
 */
final class ModuleRegistry
{
    /** @var array<string, Module> */
    private readonly array $modules;

    /**
     * @param  list<Module>  $modules
     * @param  list<string>  $loadedModules  ['*'] for every module, or their names
     */
    public function __construct(
        array $modules,
        private readonly array $loadedModules = ['*'],
        private readonly string $foundationNamespace = 'Foundation',
    ) {
        $keyed = [];

        foreach ($modules as $module) {
            if (isset($keyed[$module->name])) {
                throw ModuleException::duplicateName($module->name);
            }

            $keyed[$module->name] = $module;
        }

        $this->modules = $keyed;

        $folder = static fn (Module $module): string => rtrim(realpath($module->path()) ?: $module->path(), '/').'/';

        // Only the folders of this process: a module running elsewhere has no code here.
        $local = $this->local();

        foreach ($local as $outer) {
            foreach ($local as $inner) {
                if ($inner !== $outer && str_starts_with($folder($inner), $folder($outer))) {
                    throw ModuleException::nested($inner->name, $outer->name);
                }
            }
        }
    }

    /** @return list<Module> */
    public function all(): array
    {
        return array_values($this->modules());
    }

    /** @return list<Module> The modules this process loads. */
    public function local(): array
    {
        return $this->loadsEveryModule()
            ? $this->all()
            : array_map($this->get(...), $this->loadedModules);
    }

    public function get(string $name): Module
    {
        return $this->find($name) ?? throw ModuleException::notFound($name, array_keys($this->modules()));
    }

    public function find(string $name): ?Module
    {
        return $this->modules()[$name] ?? null;
    }

    /** The module owning a class, resolved from its namespace; null outside any module. */
    public function forClass(string $class): ?Module
    {
        $best = null;

        foreach ($this->modules() as $module) {
            if (str_starts_with($class, $module->namespace.'\\')
                && ($best === null || strlen($module->namespace) > strlen($best->namespace))) {
                $best = $module;
            }
        }

        return $best;
    }

    /** The module a foundation class belongs to, by its foundation folder: Foundation\Iam\… is iam's; null outside the foundation. */
    public function forFoundationClass(string $class, string $foundationNamespace): ?Module
    {
        $best = null;

        foreach ($this->modules() as $module) {
            $namespace = $foundationNamespace.'\\'.$module->foundation;

            if (str_starts_with($class, $namespace.'\\')
                && ($best === null || strlen($module->foundation) > strlen($best->foundation))) {
                $best = $module;
            }
        }

        return $best;
    }

    /** @return list<string> the names of the modules this process loads */
    public function localNames(): array
    {
        return array_map(static fn (Module $module): string => $module->name, $this->local());
    }

    /** The module a class belongs to: its own code, or its foundation's (its messages, its clients). */
    public function ownerOf(string $class): ?Module
    {
        return $this->forClass($class) ?? $this->forFoundationClass($class, $this->foundationNamespace);
    }

    /** The connection of a module's database; null for a module without one, or an unknown name. */
    public function connectionOf(string $name): ?string
    {
        $module = $this->find($name);

        return $module !== null && $module->hasDatabase ? $module->defaultConnection() : null;
    }

    public function isLocal(string $name): bool
    {
        $this->get($name); // an unknown name fails loudly rather than reading as "remote"

        return $this->loadsEveryModule() || in_array($name, $this->loadedModules, true);
    }

    private function loadsEveryModule(): bool
    {
        return $this->loadedModules === ['*'];
    }

    /** @return array<string, Module> */
    private function modules(): array
    {
        return $this->modules;
    }
}
