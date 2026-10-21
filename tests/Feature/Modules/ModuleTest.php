<?php

declare(strict_types=1);

use Distributable\Tests\TestCase;

uses(TestCase::class);

use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;

it('derives every convention from the module name', function () {
    $module = Module::fromName('point_of_sale', 'Apps', 'apps');

    expect($module->name)->toBe('point_of_sale')
        ->and($module->namespace)->toBe('Apps\PointOfSale')
        ->and($module->provider)->toBe('Apps\PointOfSale\Providers\PointOfSaleServiceProvider')
        ->and($module->path())->toBe(base_path('apps/PointOfSale'))
        ->and($module->classPath())->toBe(base_path('apps/PointOfSale/app'))
        ->and($module->hasDatabase)->toBeFalse();
});

it('takes its own path and namespace from the declaration, when set', function () {
    $module = Module::fromName('iam', 'Apps', 'apps', 'apps/Identity', 'Apps\Identity');

    expect($module->name)->toBe('iam')
        ->and($module->namespace)->toBe('Apps\Identity')
        ->and($module->provider)->toBe('Apps\Identity\Providers\IdentityServiceProvider')
        ->and($module->path())->toBe(base_path('apps/Identity'));
});

it('can be the project itself: its path is the project, its classes app/ under its namespace', function () {
    $module = Module::fromName('payments', 'Apps', 'apps', '.', 'App');

    expect($module->namespace)->toBe('App')
        ->and($module->provider)->toBe('App\Providers\AppServiceProvider')
        ->and(realpath($module->path()))->toBe(realpath(base_path()))
        ->and(realpath($module->classPath()))->toBe(realpath(base_path('app')));
});

it('has no database while its config/database.php names no default, even with a connection named after it', function () {
    config()->set('database.connections.point_of_sale', ['driver' => 'sqlite', 'database' => ':memory:']);

    expect(Module::fromName('point_of_sale', 'Apps', 'apps')->hasDatabase)->toBeFalse()
        ->and(Module::fromName('point_of_sale', 'Apps', 'apps')->defaultConnection())->toBeNull();
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

    expect(Module::fromName('ledger', 'Apps', $root)->defaultConnection())->toBe('main')
        ->and(Module::fromName('ledger', 'Apps', $root)->connections())->toBe(['main']);
});

it('declares only the connections it defines: a default may name one of the application', function () {
    $root = sys_get_temp_dir().'/distributable-declared-'.uniqid();
    moduleWithDatabase($root, 'Shared', ['default' => 'application']);

    expect(Module::fromName('shared', 'Apps', $root)->connections())->toBe(['application'])
        ->and(Module::fromName('shared', 'Apps', $root)->declaredConnections())->toBe([]);
});

it('rejects a name that is not lowercase snake_case', function (string $name) {
    Module::fromName($name, 'Apps', 'apps');
})->throws(ModuleException::class, 'Invalid module name')->with(['Iam', 'my-payments', '1payments', '']);
