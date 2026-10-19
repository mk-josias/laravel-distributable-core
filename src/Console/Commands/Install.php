<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Config\Modules;
use Illuminate\Console\Command;

final class Install extends Command
{
    protected $signature = 'modules:install';

    protected $description = 'Publish config/distributable.php and create the modules directory.';

    public function handle(Modules $config): int
    {
        if (! is_file(config_path('distributable.php'))) {
            copy(dirname(__DIR__, 3).'/config/distributable.php', config_path('distributable.php'));
        }

        $modules = $config->getModulesPath();
        $modules = str_starts_with($modules, DIRECTORY_SEPARATOR) ? $modules : base_path($modules);
        is_dir($modules) || mkdir($modules, 0755, true);

        $this->components->info('Laravel Distributable is installed. Next: php artisan modules:make <name>');

        return self::SUCCESS;
    }
}
