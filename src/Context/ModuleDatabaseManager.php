<?php

declare(strict_types=1);

namespace Distributable\Context;

use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Database\DatabaseManager;

use function Illuminate\Support\enum_value;

/**
 * A module's code uses only the connections its module declares: its own, its owner one and each
 * connection of its config/database.php. Laravel's and the packages' code, which open connections
 * on every module's behalf (queues, sessions, failed jobs, events), is not checked.
 */
final class ModuleDatabaseManager extends DatabaseManager
{
    /** @var array<string, list<string>> */
    private array $declared = [];

    public function connection($name = null)
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();

        if ($name !== $this->getDefaultConnection()) {
            $this->refuseUndeclared((string) $name);
        }

        return parent::connection($name);
    }

    private function refuseUndeclared(string $name): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || $file === __FILE__ || str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            foreach ($registry->all() as $module) {
                if (str_starts_with($file, $module->classPath().DIRECTORY_SEPARATOR) && ! in_array($name, $this->declaredBy($module), true)) {
                    throw ModuleException::undeclaredConnection($module->name, $name, $file);
                }
            }

            return;
        }
    }

    /** @return list<string> */
    private function declaredBy(Module $module): array
    {
        return $this->declared[$module->name] ??= $module->connections();
    }
}
