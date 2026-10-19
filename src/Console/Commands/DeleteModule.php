<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class DeleteModule extends Command
{
    protected $signature = 'modules:delete
        {name : The module to delete}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete a module: its folder and its declaration. Its database is left as it is.';

    public function handle(ModuleRegistry $registry, Filesystem $files): int
    {
        $module = $registry->get((string) $this->argument('name'));

        if (realpath($module->path()) === realpath(base_path())) {
            throw ModuleException::isTheApplication($module->name);
        }

        if (! $this->option('force') && ! $this->confirm("Delete [{$module->path()}]? The database of [{$module->name}] is left as it is.")) {
            return self::FAILURE;
        }

        $files->deleteDirectory($module->path());
        $this->undeclare($module);

        $this->components->info("Module [{$module->name}] deleted. Run modules:doctor: other modules may still use what it shared.");

        return self::SUCCESS;
    }

    private function undeclare(Module $module): void
    {
        $config = config_path('distributable.php');
        $contents = is_file($config) ? (string) file_get_contents($config) : '';
        $undeclared = preg_replace("/^[ \t]*'".preg_quote($module->name, '/')."'\s*=>\s*\[[^\]\n]*\],?[ \t]*\n/m", '', $contents, 1, $count);

        if ($count === 1 && is_string($undeclared)) {
            file_put_contents($config, $undeclared);

            return;
        }

        $this->components->warn("Remove it from config/distributable.php:'declared' => ['{$module->name}' => …].");
    }
}
