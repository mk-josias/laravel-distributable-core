<?php

declare(strict_types=1);

namespace Distributable\Console;

use Distributable\Context\ModuleContext;
use Distributable\Services\ModuleRegistry;
use Illuminate\Container\Container;

/** The services a command works on: those --service names, else the one running, else every local one. */
trait LocalServicesOption
{
    /** @return list<string> */
    protected function selectedServices(): array
    {
        $named = array_values(array_map(strval(...), (array) $this->option('service')));

        if ($named !== []) {
            return $named;
        }

        $current = Container::getInstance()->make(ModuleContext::class)->current();

        return $current !== null
            ? [$current->name]
            : Container::getInstance()->make(ModuleRegistry::class)->localNames();
    }
}
