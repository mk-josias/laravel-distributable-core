<?php

declare(strict_types=1);

namespace Distributable\Tests;

use Distributable\Context\ModuleContext;
use Distributable\Providers\DistributableServiceProvider;
use Distributable\Services\ModuleRegistry;
use Distributable\Testing\InteractsWithServices;
use Distributable\Tests\Fixtures\Orders\Providers\OrdersServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/** The `orders` application, a module of its own: it answers FindOrder and calls `billing`, which runs elsewhere. */
abstract class OrdersAppTestCase extends Orchestra
{
    use InteractsWithServices;
    use RefreshDatabase;

    /** @var list<string> the connection of the orders module, outside of which no default connection exists */
    protected $connectionsToTransact = ['testing'];

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [DistributableServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app->useAppPath(__DIR__.'/Fixtures/Orders');

        $config = $app->make(Repository::class);

        $config->set('database.default', 'testing');
        $config->set('distributable.declared', [
            'orders' => ['path' => __DIR__.'/Fixtures/Orders', 'namespace' => 'Distributable\Tests\Fixtures\Orders'],
            'billing' => ['host' => 'http://billing.test'],
        ]);
        // Billing's messages and client are its foundation's: Fixtures/Billing.
        $config->set('distributable.foundation.namespace', 'Distributable\Tests\Fixtures');
        $config->set('distributable.foundation.path', __DIR__.'/Fixtures');
        $config->set('distributable.runs', 'orders');

        // As the package registers a module's providers: once the declaration is read.
        $app->booting(static function () use ($app): void {
            $app->register(OrdersServiceProvider::class);
        });
        $config->set('distributable.rpc.secret', 'test-secret');
        $config->set('distributable.events.streams.default.driver', 'array');
        $config->set('distributable.events.reliable_publish', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distributable.events.reliable_publish', false);

        // The test runs as the application's own code: inside its one module.
        $this->app->make(ModuleContext::class)->enter($this->app->make(ModuleRegistry::class)->get('orders'));
    }
}
