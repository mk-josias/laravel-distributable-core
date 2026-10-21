<?php

declare(strict_types=1);

namespace Distributable\Context\Queue;

use Distributable\Context\ModuleContext;
use Distributable\Data\Module;
use Distributable\Services\ModuleRegistry;
use Illuminate\Support\Facades\Context;

/** Where each module keeps its failed jobs and its batches: its own connection, unless its config/queue.php names another of its connections. */
final class Databases
{
    public const string FAILED = 'queue.failed.database';

    public const string BATCHING = 'queue.batching.database';

    /** @return list<Module> the local modules that have a database */
    public static function all(ModuleRegistry $registry): array
    {
        return array_values(array_filter($registry->local(), static fn (Module $module): bool => $module->hasDatabase));
    }

    /** @return list<Module> the module that runs, when one does (a command run with --module, a job); every local module with a database otherwise */
    public static function modules(ModuleRegistry $registry): array
    {
        $name = Context::getHidden(ModuleContext::CONTEXT_KEY);
        $module = is_string($name) ? $registry->find($name) : null;

        return $module !== null && $module->hasDatabase ? [$module] : self::all($registry);
    }

    /** A value that is not a connection the module declares is not read: it never sends data to another module. */
    public static function for(Module $module, string $setting): string
    {
        $named = config("distributable.overlays.{$module->name}.{$setting}");

        return is_string($named) && in_array($named, $module->connections(), true)
            ? $named
            : (string) $module->defaultConnection();
    }
}
