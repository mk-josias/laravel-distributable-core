<?php

declare(strict_types=1);

namespace Distributable\Services\Modules;

use Distributable\Contracts\PublishesMigrations;
use Distributable\Data\Module;
use Distributable\Services\Shadows\ShadowRegistry;
use Illuminate\Container\Container;

/**
 * The migrations of a module database: the application's (laravel-microservices' outbox tables
 * among them), the module's and its copies'.
 */
final readonly class ModuleMigrations
{
    public function __construct(private ShadowRegistry $shadows) {}

    /** @return list<string> */
    public function forModule(Module $module): array
    {
        $paths = $this->applicationPaths();

        if (is_dir($module->path().'/database/migrations')) {
            $paths[] = $module->path().'/database/migrations';
        }

        return [...$paths, ...$this->shadowMigrations($module)];
    }

    /**
     * Laravel skips the loadMigrationsFrom() paths once a --path is given, and every run here gives one.
     *
     * @return list<string>
     */
    private function applicationPaths(): array
    {
        /** @var list<string> $packages */
        $packages = Container::getInstance()->make('migrator')->paths();

        return array_values(array_unique([database_path('migrations'), ...$packages]));
    }

    /**
     * The migrations of the copies this module keeps: the files or folders each names in migrations().
     *
     * @return list<string>
     */
    private function shadowMigrations(Module $module): array
    {
        $paths = [];

        foreach ($this->shadows->localShadows($module->name) as $shadow) {
            if (is_subclass_of($shadow, PublishesMigrations::class)) {
                $paths = [...$paths, ...$shadow::migrations()];
            }
        }

        return array_values(array_unique($paths));
    }
}
