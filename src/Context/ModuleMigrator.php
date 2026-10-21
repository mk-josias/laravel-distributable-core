<?php

declare(strict_types=1);

namespace Distributable\Context;

use Distributable\Services\Shadows\ShadowRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migrator;

/**
 * Laravel's migrator, whose paths are those of the context that runs: what every provider loads, plus, in a module, the
 * application's database/migrations, the folders its own provider loads and the migrations of the copies it keeps.
 * migrate:* add database_path('migrations') themselves: the module's own, while it runs.
 */
final class ModuleMigrator extends Migrator
{
    /** @var array<string, list<string>> */
    private array $modulePaths = [];

    public function modulePath(string $module, string $path): void
    {
        $this->modulePaths[$module] = array_values(array_unique([...$this->modulePaths[$module] ?? [], $path]));
    }

    /** @return list<string> */
    public function paths(): array
    {
        $container = Container::getInstance();
        $context = $container->make(ModuleContext::class);
        $module = $context->current();

        if ($module === null) {
            return array_values(parent::paths());
        }

        $copies = [];

        foreach ($container->make(ShadowRegistry::class)->localShadows($module->name) as $shadow) {
            $copies = [...$copies, ...$shadow::migrations()];
        }

        return array_values(array_unique([$context->applicationDatabasePath('migrations'), ...parent::paths(), ...$this->modulePaths[$module->name] ?? [], ...$copies]));
    }
}
