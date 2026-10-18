<?php

declare(strict_types=1);

use Distributable\Tests\TestCase;

uses(TestCase::class);

use Apps\Billing\Providers\BillingServiceProvider;
use Apps\Shipping\Providers\ShippingServiceProvider;
use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Support\Str;

it('derives every convention from the module name', function () {
    $module = Module::fromName('point_of_sale', 'Apps', 'apps');

    expect($module->name)->toBe('point_of_sale')
        ->and($module->namespace)->toBe('Apps\PointOfSale')
        ->and($module->provider)->toBe('Apps\PointOfSale\Providers\PointOfSaleServiceProvider')
        ->and($module->path())->toBe(base_path('apps/PointOfSale'))
        ->and($module->classPath())->toBe(base_path('apps/PointOfSale/app'))
        ->and($module->connection())->toBe('point_of_sale')
        ->and($module->ownerConnection())->toBe('point_of_sale');
});

it('takes its own path, namespace and foundation folder from the declaration, when set', function () {
    $module = Module::fromName('iam', 'Apps', 'apps', 'Accounts', 'apps/Identity', 'Apps\Identity');

    expect($module->name)->toBe('iam')
        ->and($module->namespace)->toBe('Apps\Identity')
        ->and($module->provider)->toBe('Apps\Identity\Providers\IdentityServiceProvider')
        ->and($module->path())->toBe(base_path('apps/Identity'))
        ->and($module->foundation)->toBe('Accounts')
        ->and($module->connection())->toBe('iam');
});

it('can be the project itself: its path is the project, its classes app/ under its namespace', function () {
    $module = Module::fromName('shop', 'Apps', 'apps', null, '.', 'App');

    expect($module->namespace)->toBe('App')
        ->and($module->provider)->toBe('App\Providers\AppServiceProvider')
        ->and(realpath($module->path()))->toBe(realpath(base_path()))
        ->and(realpath($module->classPath()))->toBe(realpath(base_path('app')));
});

it('has no database when no connection is named after the module', function () {
    expect(Module::fromName('point_of_sale', 'Apps', 'apps')->hasDatabase)->toBeFalse();
});

it('has a database once the root config/database.php declares a connection named after the module', function () {
    config()->set('database.connections.point_of_sale', ['driver' => 'sqlite', 'database' => ':memory:']);

    expect(Module::fromName('point_of_sale', 'Apps', 'apps')->hasDatabase)->toBeTrue();
});

/** A module under a temporary root, with this config/database.php and a provider. */
function moduleWithDatabase(string $root, string $studly, array $database): void
{
    $path = "{$root}/{$studly}";
    @mkdir("{$path}/config", 0755, true);
    @mkdir("{$path}/app/Providers", 0755, true);
    file_put_contents("{$path}/config/database.php", '<?php return '.var_export($database, true).';');
    file_put_contents("{$path}/app/Providers/{$studly}ServiceProvider.php", "<?php namespace Apps\\{$studly}\\Providers; final class {$studly}ServiceProvider extends \\Distributable\\Providers\\ServiceProvider {}");
    require_once "{$path}/app/Providers/{$studly}ServiceProvider.php";
}

it('runs on the default its config/database.php names, as the root one names the application\'s', function () {
    $root = sys_get_temp_dir().'/distributable-default-'.uniqid();
    moduleWithDatabase($root, 'Ledger', ['default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite']]]);

    expect(Module::fromName('ledger', 'Apps', $root)->connection())->toBe('main')
        ->and(Module::fromName('ledger', 'Apps', $root)->connections())->toBe(['main'])
        ->and(Module::fromName('point_of_sale', 'Apps', $root)->connection())->toBe('point_of_sale');
});

it('migrates on the connection its config/database.php names as owner, and on its own without one', function () {
    $root = sys_get_temp_dir().'/distributable-owner-'.uniqid();
    moduleWithDatabase($root, 'Till', ['default' => 'till', 'owner' => 'till_owner', 'connections' => ['till' => ['driver' => 'sqlite'], 'till_owner' => ['driver' => 'sqlite']]]);
    moduleWithDatabase($root, 'Kiosk', ['connections' => ['kiosk' => ['driver' => 'sqlite']]]);

    expect(Module::fromName('till', 'Apps', $root)->ownerConnection())->toBe('till_owner')
        ->and(Module::fromName('till', 'Apps', $root)->connections())->toBe(['till', 'till_owner'])
        ->and(Module::fromName('kiosk', 'Apps', $root)->ownerConnection())->toBe('kiosk');
});

it('refuses two modules declaring one connection name', function () {
    $root = sys_get_temp_dir().'/distributable-shared-'.uniqid();
    $connection = 'main_'.strtolower(Str::random(6));
    moduleWithDatabase($root, 'Billing', ['connections' => [$connection => ['driver' => 'sqlite']]]);
    moduleWithDatabase($root, 'Shipping', ['connections' => [$connection => ['driver' => 'sqlite']]]);
    config()->set('distributable.declared', ['billing' => [], 'shipping' => []]);
    config()->set('distributable.modules.path', $root);
    app()->forgetInstance(ModuleRegistry::class);

    app()->register(BillingServiceProvider::class);
    app()->register(ShippingServiceProvider::class);
})->throws(ModuleException::class, 'both declare connection');

it('rejects a name that is not lowercase snake_case', function (string $name) {
    Module::fromName($name, 'Apps', 'apps');
})->throws(ModuleException::class, 'Invalid module name')->with(['Iam', 'my-shop', '1shop', '']);
