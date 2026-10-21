<?php

declare(strict_types=1);

namespace Distributable\Console;

use Distributable\Context\ModuleContext;
use Illuminate\Foundation\Console\VendorPublishCommand;

/**
 * A package names where its files go when it boots, outside every module: vendor:publish --module=iam puts them where they
 * lie in iam instead, for what follows the module while it runs (its app/, database/, config/ and views).
 */
final class ModulePublishCommand extends VendorPublishCommand
{
    protected function publishItem($from, $to): void
    {
        parent::publishItem($from, $this->laravel->make(ModuleContext::class)->modulePath((string) $to));
    }
}
