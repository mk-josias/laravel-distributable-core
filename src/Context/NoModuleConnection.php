<?php

declare(strict_types=1);

namespace Distributable\Context;

use Closure;
use Distributable\Exceptions\ModuleException;
use Illuminate\Database\Connection;

/** The default connection outside every module: it can be built, and refuses everything it is asked. */
final class NoModuleConnection extends Connection
{
    public function __construct()
    {
        parent::__construct(static fn () => throw ModuleException::noModuleRunning(), '', '', ['name' => ModuleContext::NO_MODULE]);
    }

    public function getSchemaBuilder()
    {
        throw ModuleException::noModuleRunning();
    }

    /** @param  array<array-key, mixed>  $bindings */
    protected function run($query, $bindings, Closure $callback)
    {
        throw ModuleException::noModuleRunning();
    }
}
