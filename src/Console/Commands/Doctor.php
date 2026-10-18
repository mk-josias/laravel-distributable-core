<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Config\Modules;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Rpc\RpcServices;
use Distributable\Services\Shadows\ShadowRegistry;
use Distributable\Testing\Boundaries;
use Illuminate\Console\Command;
use RpcHttp\RpcHttp;

final class Doctor extends Command
{
    protected $signature = 'modules:doctor';

    protected $description = 'Check that every module can run: declaration, provider, connections, remote hosts, boundaries.';

    public function handle(ModuleRegistry $registry, Modules $config, RpcServices $services, Boundaries $boundaries, ShadowRegistry $shadows): int
    {
        $problems = [];
        $modulesPath = $config->getModulesPath();
        $root = str_starts_with($modulesPath, DIRECTORY_SEPARATOR) ? $modulesPath : base_path($modulesPath);

        $declared = array_map(static fn ($module): string => realpath($module->path()) ?: $module->path(), $registry->all());

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (! in_array(realpath($directory) ?: $directory, $declared, true)) {
                $problems[] = "[{$modulesPath}/".basename($directory).'] is not declared in distributable.declared.';
            }
        }

        foreach ($registry->local() as $module) {
            if (! is_file($module->providersFile())) {
                $problems[] = "[{$module->name}] {$module->providersFile()} does not exist.";
            }

            foreach ($module->providers() as $provider) {
                if (! class_exists($provider)) {
                    $problems[] = "[{$module->name}] provider {$provider} does not exist.";
                }
            }

            $tables = [];

            foreach ($shadows->localShadows($module->name) as $shadow) {
                $tables[(new $shadow)->getTable()][] = $shadow;

                foreach ($shadow::migrations() as $path) {
                    if (! file_exists($path) || (is_dir($path) && (glob($path.'/*_*.php') ?: []) === [])) {
                        $problems[] = "[{$module->name}] keeps {$shadow}, whose migrations [{$path}] hold no migration.";
                    }
                }
            }

            foreach ($tables as $table => $copies) {
                if (count($copies) > 1) {
                    $problems[] = "[{$module->name}] keeps two copies in table [{$table}]: ".implode(', ', $copies).'. Give each its own $table.';
                }
            }
        }

        foreach ($services->all() as $contract => $service) {
            if ($service['service'] === null) {
                $problems[] = "{$contract} is not in the foundation of a declared module.";
            } elseif (! $registry->isLocal($service['service']) && $config->getHost($service['service']) === null) {
                $problems[] = "[{$service['service']}] runs elsewhere and serves {$contract}, but distributable.declared.{$service['service']}.host is not set.";
            }
        }

        if ($services->all() !== [] && class_exists(RpcHttp::class) && app(RpcHttp::class)->getSecret() === '') {
            $problems[] = 'Modules serve RPC contracts but microservices.rpc.secret is empty: set MICROSERVICES_RPC_SECRET or APP_KEY.';
        }

        foreach ($boundaries->violations() as $violation) {
            $problems[] = "Boundary crossed: {$violation}";
        }

        foreach ($this->conflictingConfig($registry) as $key => $modules) {
            $problems[] = "Modules [{$modules}] set config [{$key}] to different values: in one process, the module registered last wins.";
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        if ($problems === []) {
            $this->components->info('Every module can run.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, string> each config key two local modules set differently, with those modules */
    private function conflictingConfig(ModuleRegistry $registry): array
    {
        $values = [];

        foreach ($registry->local() as $module) {
            foreach (glob($module->path().'/config/*.php') ?: [] as $file) {
                foreach ($this->scalars(require $file, pathinfo($file, PATHINFO_FILENAME)) as $key => $value) {
                    $values[$key][$module->name] = $value;
                }
            }
        }

        $conflicts = [];

        foreach ($values as $key => $byModule) {
            // A value the application already sets is each module's own, applied while it runs.
            if (config()->has("distributable.overlay_base.{$key}")) {
                continue;
            }

            if (count(array_unique(array_map(serialize(...), $byModule))) > 1) {
                $conflicts[$key] = implode(', ', array_keys($byModule));
            }
        }

        return $conflicts;
    }

    /**
     * Lists are left out: the modules' items add up instead of replacing each other.
     *
     * @return array<string, mixed>
     */
    private function scalars(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            return [$key => $value];
        }

        if (array_is_list($value)) {
            return [];
        }

        $scalars = [];

        foreach ($value as $child => $childValue) {
            $scalars = [...$scalars, ...$this->scalars($childValue, "{$key}.{$child}")];
        }

        return $scalars;
    }
}
