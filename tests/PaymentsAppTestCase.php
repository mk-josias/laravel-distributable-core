<?php

declare(strict_types=1);

namespace Distributable\Tests;

use Distributable\Context\ModuleContext;
use Distributable\Providers\DistributableServiceProvider;
use Distributable\Services\ModuleRegistry;
use Distributable\Testing\ModuleAware;
use Distributable\Tests\Fixtures\Payments\Providers\PaymentsServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/** The `payments` application, a module of its own: it answers FindPayment and calls `messaging`, which runs elsewhere. */
abstract class PaymentsAppTestCase extends Orchestra
{
    use ModuleAware;
    use RefreshDatabase {
        migrateFreshUsing as private laravelMigrateFreshUsing;
    }

    /** @var list<string> the connection of the payments module, outside of which no default connection exists */
    protected $connectionsToTransact = ['testing'];

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [DistributableServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app->useAppPath(__DIR__.'/Fixtures/Payments');

        $config = $app->make(Repository::class);

        $config->set('database.default', 'testing');
        $config->set('distributable.declared', [
            'payments' => ['path' => __DIR__.'/Fixtures/Payments', 'namespace' => 'Distributable\Tests\Fixtures\Payments'],
            'messaging' => ['host' => 'http://messaging.test'],
        ]);
        // Messaging's messages and client are its foundation's: Fixtures/Messaging.
        $config->set('distributable.foundation.path', __DIR__.'/Fixtures');
        $config->set('distributable.runs', 'payments');

        // As the package registers a module's providers: once the declaration is read.
        $app->booting(static function () use ($app): void {
            $app->register(PaymentsServiceProvider::class);
        });
        $config->set('distributable.events.streams.default.driver', 'array');
        $config->set('distributable.events.reliable_publish', true);
    }

    /** @return array<string, mixed> RefreshDatabase's migrate:fresh, in the module whose database it rebuilds */
    protected function migrateFreshUsing(): array
    {
        return [...$this->laravelMigrateFreshUsing(), '--module' => ['payments']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distributable.events.reliable_publish', false);

        // The test runs as the application's own code: inside its one module.
        $this->app->make(ModuleContext::class)->enter($this->app->make(ModuleRegistry::class)->get('payments'));
    }
}
