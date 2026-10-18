<?php

declare(strict_types=1);

use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Distributable\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Shop\ShopServiceProvider;

/** An application of one module: the module is the Testbench application itself. */
abstract class ModuleOfItsOwnTestCase extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('distributable.declared', ['shop' => ['path' => '.', 'namespace' => 'Shop']]);
        $app->make(Repository::class)->set('microservices.events.streams.default.driver', 'array');
    }
}

uses(ModuleOfItsOwnTestCase::class);

it('is the project, and keeps the application\'s config as it is: nothing set aside as its own', function () {
    require_once dirname(__DIR__, 2).'/Fixtures/shop/ShopServiceProvider.php';
    app()->register(ShopServiceProvider::class);
    $module = app(ModuleRegistry::class)->get('shop');

    expect(realpath($module->path()))->toBe(realpath(base_path()))
        ->and(config('distributable.overlays.shop'))->toBeNull();
});

it('leaves the application\'s routes/ to bootstrap/app.php: none is loaded again under the module\'s prefix', function () {
    $routes = base_path('routes');
    $created = ! is_dir($routes) && mkdir($routes);
    file_put_contents("{$routes}/web.php", "<?php\n\nIlluminate\\Support\\Facades\\Route::get('ping', fn () => 'pong');\n");

    try {
        require_once dirname(__DIR__, 2).'/Fixtures/shop/ShopServiceProvider.php';
        app()->register(ShopServiceProvider::class);

        expect(collect(Route::getRoutes()->getRoutes())->map->uri()->all())->not->toContain('shop/web/ping');
    } finally {
        unlink("{$routes}/web.php");
        $created && rmdir($routes);
    }
});

it('is never deleted: its folder is the application\'s', function () {
    $this->artisan('modules:delete', ['name' => 'shop', '--force' => true]);
})->throws(ModuleException::class, 'deleting it would delete the application');
