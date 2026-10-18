<?php

declare(strict_types=1);

use Apps\Analytics\Models\UserShadow;
use Distributable\Data\Module;
use Distributable\Services\Composer\ComposerAutoload;
use Distributable\Services\Migrations\ModuleMigrations;
use Distributable\Services\ModuleRegistry;
use Distributable\Tests\ModuleAppTestCase;
use Foundation\Iam\Shadows\MisplacedShadow;
use Foundation\Iam\Shadows\UserShadow as IamUserShadow;
use Illuminate\Support\Facades\File;

uses(ModuleAppTestCase::class);

it('installs the config, the modules directory and the foundation provider, then a module declares itself in that config', function () {
    $root = sys_get_temp_dir().'/distributable-install-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.modules.path', $root.'/apps');
    config()->set('distributable.foundation.path', $root.'/foundation');

    try {
        $this->artisan('modules:install')->assertSuccessful();

        expect(is_file($root.'/config/distributable.php'))->toBeTrue()
            ->and(is_dir($root.'/apps'))->toBeTrue()
            ->and(file_get_contents($root.'/foundation/FoundationServiceProvider.php'))
            ->toContain('namespace Foundation;')
            ->toContain('final class FoundationServiceProvider extends BaseServiceProvider');

        $this->artisan('modules:make billing')->assertSuccessful();

        expect((require $root.'/config/distributable.php')['declared'])->toHaveKey('billing');
    } finally {
        File::deleteDirectory($root);
    }
});

it('creates a module, its provider and its foundation directory, and declares it', function () {
    $root = sys_get_temp_dir().'/distributable-make-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 3).'/config/distributable.php', $root.'/config/distributable.php');
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.modules.path', $root.'/apps');
    config()->set('distributable.foundation.path', $root.'/foundation');

    try {
        $this->artisan('modules:make point_of_sale --database')->assertSuccessful();

        expect(file_get_contents($root.'/apps/PointOfSale/app/Providers/PointOfSaleServiceProvider.php'))
            ->toContain('namespace Apps\PointOfSale\Providers;')
            ->toContain('final class PointOfSaleServiceProvider extends ServiceProvider {}')
            ->and(file_get_contents($root.'/apps/PointOfSale/bootstrap/providers.php'))->toContain('Apps\PointOfSale\Providers\PointOfSaleServiceProvider::class')
            ->and(is_file($root.'/apps/PointOfSale/routes/api.php'))->toBeTrue()
            ->and(file_get_contents($root.'/apps/PointOfSale/config/database.php'))->toContain("'point_of_sale_owner'")
            ->and(is_dir($root.'/foundation/PointOfSale/Contracts'))->toBeTrue()
            ->and((require $root.'/config/distributable.php')['declared'])->toBe(['point_of_sale' => []]);

        $this->artisan('modules:make point_of_sale')->assertFailed();
    } finally {
        File::deleteDirectory($root);
    }
});

it('adds the module and the foundation to composer.json for the tools that read it, and tells an entry gone stale', function () {
    $root = sys_get_temp_dir().'/distributable-composer-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 3).'/config/distributable.php', $root.'/config/distributable.php');
    File::put($root.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}, "extra": {}}');
    $this->app->setBasePath($root);
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.modules.path', 'apps');
    config()->set('distributable.foundation.path', 'foundation');

    try {
        $this->artisan('modules:make billing')->expectsOutputToContain('Added to composer.json')->assertSuccessful();

        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

        expect($composer['autoload']['psr-4'])->toBe([
            'App\\' => 'app/',
            'Foundation\\' => 'foundation/',
            'Apps\\Billing\\' => 'apps/Billing/app/',
            'Apps\\Billing\\Database\\Factories\\' => 'apps/Billing/database/factories/',
            'Apps\\Billing\\Database\\Seeders\\' => 'apps/Billing/database/seeders/',
        ])->and($composer['autoload-dev']['psr-4'])->toBe(['Apps\\Billing\\Tests\\' => 'apps/Billing/tests/'])
            ->and(file_get_contents($root.'/composer.json'))->toContain('"extra": {}');

        $autoload = $this->app->make(ComposerAutoload::class);

        expect($autoload->stale(Module::fromName('billing', 'Apps', 'apps')))->toBe([])
            ->and($autoload->stale(Module::fromName('billing', 'Apps', 'modules'))['Apps\\Billing\\'])
            ->toBe(['declared' => 'apps/Billing/app/', 'expected' => 'modules/Billing/app/']);
    } finally {
        File::deleteDirectory($root);
    }
});

it('deletes a module: its folder, its foundation folder, its declaration and its composer.json entries', function () {
    $root = sys_get_temp_dir().'/distributable-delete-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 3).'/config/distributable.php', $root.'/config/distributable.php');
    File::put($root.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($root);
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.modules.path', 'apps');
    config()->set('distributable.foundation.path', 'foundation');

    try {
        $this->artisan('modules:make billing')->assertSuccessful();
        $this->artisan('modules:make shipping')->assertSuccessful();
        config()->set('distributable.declared', ['billing' => [], 'shipping' => []]);
        $this->app->forgetInstance(ModuleRegistry::class);

        $this->artisan('modules:delete billing --force')->assertSuccessful();

        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

        expect(is_dir($root.'/apps/Billing'))->toBeFalse()
            ->and(is_dir($root.'/foundation/Billing'))->toBeFalse()
            ->and(is_dir($root.'/apps/Shipping'))->toBeTrue()
            ->and((require $root.'/config/distributable.php')['declared'])->toBe(['shipping' => []])
            ->and(array_keys($composer['autoload']['psr-4']))->toBe([
                'App\\', 'Foundation\\', 'Apps\\Shipping\\', 'Apps\\Shipping\\Database\\Factories\\', 'Apps\\Shipping\\Database\\Seeders\\',
            ])
            ->and(array_keys($composer['autoload-dev']['psr-4']))->toBe(['Apps\\Shipping\\Tests\\']);
    } finally {
        File::deleteDirectory($root);
    }
});

it('lists every module and where it runs', function () {
    config()->set('distributable.declared.iam', []);

    $this->artisan('modules:list')
        ->expectsTable(['Module', 'Namespace', 'Runs here', 'Database', 'Remote host'], [
            ['analytics', 'Apps\Analytics', 'yes', 'analytics', '—'],
            ['gateway', 'Apps\Gateway', 'yes', '—', '—'],
            ['iam', 'Apps\Iam', 'yes', 'iam', '—'],
        ])
        ->assertSuccessful();
});

it('fails on a module with no bootstrap/providers.php, and names it', function () {
    config()->set('distributable.declared.ghost', []);
    $this->app->forgetInstance(ModuleRegistry::class);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('[ghost] '.config('distributable.modules.path').'/Ghost/bootstrap/providers.php does not exist.')
        ->assertFailed();
});

it('fails on a provider that bootstrap/providers.php lists and that does not exist, and names it', function () {
    $file = config('distributable.modules.path').'/Ghost/bootstrap/providers.php';
    File::ensureDirectoryExists(dirname($file));
    File::put($file, "<?php\n\nreturn ['Apps\\Ghost\\Providers\\GhostServiceProvider'];\n");
    config()->set('distributable.declared.ghost', []);
    $this->app->forgetInstance(ModuleRegistry::class);

    try {
        $this->artisan('modules:doctor')
            ->expectsOutputToContain('[ghost] provider Apps\Ghost\Providers\GhostServiceProvider does not exist.')
            ->assertFailed();
    } finally {
        File::deleteDirectory(dirname($file, 2));
    }
});

it('fails on a module folder that distributable.declared does not declare', function () {
    config()->set('distributable.declared', ['analytics' => [], 'iam' => []]);
    $this->app->forgetInstance(ModuleRegistry::class);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('/Gateway] is not declared in distributable.declared.')
        ->assertFailed();
});

it('fails when modules serve RPC contracts and no secret signs the calls', function () {
    config()->set('microservices.rpc.secret', '');

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('microservices.rpc.secret is empty')
        ->assertFailed();
});

it('fails on two modules setting one new config key to different values, and leaves lists and per-module values alone', function () {
    $file = dirname(__DIR__, 2).'/Fixtures/apps/Analytics/config/iam.php';
    file_put_contents($file, "<?php\n\nreturn ['flag' => false, 'items' => ['from-analytics'], 'nested' => ['override' => 'analytics']];\n");

    try {
        $this->artisan('modules:doctor')
            ->expectsOutputToContain('Modules [analytics, iam] set config [iam.flag] to different values')
            ->doesntExpectOutputToContain('[iam.items')
            ->doesntExpectOutputToContain('[iam.nested.override]')
            ->assertFailed();
    } finally {
        unlink($file);
    }
});

it('fails on a cache, queue or session kept in a database no module owns', function () {
    config()->set('queue.default', 'database');

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('[queue.default] is the database driver')
        ->assertFailed();
});

it('fails on a module keeping two copies in one table', function () {
    config()->set('microservices.shadows.analytics', [UserShadow::class, IamUserShadow::class]);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('[analytics] keeps two copies in table [iam_users]')
        ->assertFailed();
});

it('fails on a copy naming a folder without any migration', function () {
    config()->set('microservices.shadows.analytics', [UserShadow::class, MisplacedShadow::class]);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('[analytics] keeps '.MisplacedShadow::class.', whose migrations [')
        ->doesntExpectOutputToContain('keeps '.UserShadow::class)
        ->assertFailed();
});

it('runs the migrations a copy names in the database of the module keeping it', function () {
    expect(app(ModuleMigrations::class)->forModule(app(ModuleRegistry::class)->get('analytics')))
        ->toContain(dirname(__DIR__, 2).'/Fixtures/foundation/Iam/Shadows/../database/shadows/users');
});

it('passes once every module can run', function () {
    $this->artisan('modules:doctor')->expectsOutputToContain('Every module can run.')->assertSuccessful();
});
