<?php

declare(strict_types=1);

namespace Distributable\Tests;

use Distributable\Testing\ModuleAware;
use Foundation\FoundationServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/** Boots the app against the fixture module tree, as a consumer application would. */
abstract class ModuleAppTestCase extends TestCase
{
    use ModuleAware;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FoundationServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);

        $config->set('distributable.declared', [
            'analytics' => [],
            'gateway' => [],
            'iam' => ['host' => 'http://iam.test'],
        ]);
        $config->set('distributable.modules.path', __DIR__.'/Fixtures/apps');
        $config->set('distributable.foundation.path', __DIR__.'/Fixtures/foundation');
        $config->set('distributable.status_route', '/');

        // The group iam's routes/admin.php lands in.
        $app->make('router')->middlewareGroup('admin', []);

        $config->set('distributable.events.streams.default.driver', 'array');

        // Root config the iam module's config/iam.php fragment merges over.
        $config->set('iam', [
            'items' => ['from-root'],
            'nested' => ['kept' => 'root', 'override' => 'root'],
            'codes' => [403 => 'root forbidden', 404 => 'root not found'],
            'cleared' => ['root'],
        ]);

    }
}
