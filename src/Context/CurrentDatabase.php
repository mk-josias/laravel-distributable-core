<?php

declare(strict_types=1);

namespace Distributable\Context;

use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The database manager of the context that runs, asked at each call: a service built once (the migrator, a command)
 * reaches the connections of the module it works in, never those the application opened.
 */
final class CurrentDatabase implements ConnectionResolverInterface
{
    public function connection($name = null): ConnectionInterface
    {
        return $this->manager()->connection($name);
    }

    public function getDefaultConnection(): string
    {
        return $this->manager()->getDefaultConnection();
    }

    public function setDefaultConnection($name): void
    {
        $this->manager()->setDefaultConnection($name);
    }

    private function manager(): ConnectionResolverInterface
    {
        return Container::getInstance()->make('db');
    }
}
