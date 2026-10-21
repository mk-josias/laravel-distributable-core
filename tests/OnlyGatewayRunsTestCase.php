<?php

declare(strict_types=1);

namespace Distributable\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/** The fixture app with only `gateway` running, a module without a database. */
abstract class OnlyGatewayRunsTestCase extends ModuleAppTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make(Repository::class)->set('distributable.runs', 'gateway');
    }
}
