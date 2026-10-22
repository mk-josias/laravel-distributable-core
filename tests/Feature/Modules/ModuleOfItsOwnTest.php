<?php

declare(strict_types=1);

use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Distributable\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Payments\PaymentsServiceProvider;

/** An application of one module: the module is the Testbench application itself. */
abstract class ModuleOfItsOwnTestCase extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('distributable.declared', ['payments' => ['path' => '.', 'namespace' => 'Payments']]);
        $app->make(Repository::class)->set('distributable.events.streams.default.driver', 'array');
    }
}

// As a real `php artisan` run does, so CommandStarting fires.
uses(ModuleOfItsOwnTestCase::class, WithConsoleEvents::class);

it('is the project, and keeps the application\'s config as it is: nothing set aside as its own', function () {
    require_once dirname(__DIR__, 2).'/Fixtures/payments-app/PaymentsServiceProvider.php';
    app()->register(PaymentsServiceProvider::class);
    $module = app(ModuleRegistry::class)->get('payments');

    expect(realpath($module->path()))->toBe(realpath(base_path()))
        ->and(config('distributable.overlays.payments'))->toBeNull();
});

it('leaves the application\'s routes/ to bootstrap/app.php: none is loaded again under the module\'s prefix', function () {
    $routes = base_path('routes');
    $created = ! is_dir($routes) && mkdir($routes);
    file_put_contents("{$routes}/web.php", "<?php\n\nIlluminate\\Support\\Facades\\Route::get('ping', fn () => 'pong');\n");

    try {
        require_once dirname(__DIR__, 2).'/Fixtures/payments-app/PaymentsServiceProvider.php';
        app()->register(PaymentsServiceProvider::class);

        expect(collect(Route::getRoutes()->getRoutes())->map->uri()->all())->not->toContain('payments/web/ping');
    } finally {
        unlink("{$routes}/web.php");
        $created && rmdir($routes);
    }
});

it('runs the application\'s routes/web.php in the module, a closure route included', function () {
    $routes = base_path('routes');
    $created = ! is_dir($routes) && mkdir($routes);
    file_put_contents("{$routes}/web.php", "<?php\n\nIlluminate\\Support\\Facades\\Route::get('connection', fn () => Illuminate\\Support\\Facades\\DB::getDefaultConnection());\n");

    try {
        Route::group([], "{$routes}/web.php");

        $this->get('connection')->assertOk()->assertContent((string) app(ModuleRegistry::class)->get('payments')->defaultConnection());
    } finally {
        unlink("{$routes}/web.php");
        $created && rmdir($routes);
    }
});

it('runs a command no module owns in the module, with no --module', function () {
    Artisan::command('connection', fn () => $this->line(DB::getDefaultConnection()));

    $this->artisan('connection')->expectsOutput((string) app(ModuleRegistry::class)->get('payments')->defaultConnection());
});

it('is never deleted: its folder is the application\'s', function () {
    $this->artisan('modules:delete', ['name' => 'payments', '--force' => true]);
})->throws(ModuleException::class, 'deleting it would delete the application');
