<?php

declare(strict_types=1);

use Apps\Analytics\Models\UserShadow;
use Distributable\Contracts\Rpc\ReportsRpcProblems;
use Distributable\Contracts\Rpc\Transport;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Rpc\TransportManager;
use Distributable\Tests\ModuleAppTestCase;
use Foundation\Iam\Shadows\MisplacedShadow;
use Foundation\Iam\Shadows\UserShadow as IamUserShadow;
use Illuminate\Support\Facades\File;

uses(ModuleAppTestCase::class);

it('installs the config and the modules directory, then a module declares itself in that config', function () {
    $root = sys_get_temp_dir().'/distributable-install-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.modules.path', $root.'/apps');
    config()->set('distributable.foundation.path', $root.'/foundation');

    try {
        $this->artisan('modules:install')->assertSuccessful();

        expect(is_file($root.'/config/distributable.php'))->toBeTrue()
            ->and(is_dir($root.'/apps'))->toBeTrue()
            ->and(is_dir($root.'/foundation'))->toBeFalse();

        $this->artisan('modules:make payments')->assertSuccessful();

        expect((require $root.'/config/distributable.php')['declared'])->toHaveKey('payments');
    } finally {
        File::deleteDirectory($root);
    }
});

it('creates a module and its provider, and declares it', function () {
    $root = sys_get_temp_dir().'/distributable-make-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 3).'/config/distributable.php', $root.'/config/distributable.php');
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.modules.path', $root.'/apps');
    config()->set('distributable.foundation.path', $root.'/foundation');

    try {
        $this->artisan('modules:make point_of_sale')->assertSuccessful();

        expect(file_get_contents($root.'/apps/PointOfSale/app/Providers/PointOfSaleServiceProvider.php'))
            ->toContain('namespace Apps\PointOfSale\Providers;')
            ->toContain('final class PointOfSaleServiceProvider extends ServiceProvider {}')
            ->and(file_get_contents($root.'/apps/PointOfSale/bootstrap/providers.php'))->toContain('Apps\PointOfSale\Providers\PointOfSaleServiceProvider::class')
            ->and(is_file($root.'/apps/PointOfSale/routes/api.php'))->toBeTrue()
            ->and(file_get_contents($root.'/apps/PointOfSale/config/database.php'))->toContain("'point_of_sale_owner'")
            ->and(is_dir($root.'/apps/PointOfSale/app/Models'))->toBeTrue()
            ->and(is_dir($root.'/apps/PointOfSale/database/migrations'))->toBeTrue()
            ->and(is_dir($root.'/apps/PointOfSale/tests/Feature'))->toBeTrue()
            ->and(is_dir($root.'/foundation'))->toBeFalse()
            ->and((require $root.'/config/distributable.php')['declared'])->toBe(['point_of_sale' => []]);

        $this->artisan('modules:make point_of_sale')->assertFailed();
    } finally {
        File::deleteDirectory($root);
    }
});

it('leaves composer.json to the application: a new module writes none', function () {
    $root = sys_get_temp_dir().'/distributable-composer-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 3).'/config/distributable.php', $root.'/config/distributable.php');
    File::put($root.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}, "extra": {}}');
    $this->app->setBasePath($root);
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.modules.path', 'apps');
    config()->set('distributable.foundation.path', 'foundation');

    try {
        $this->artisan('modules:make payments')->assertSuccessful();

        expect(file_get_contents($root.'/composer.json'))->toBe('{"autoload": {"psr-4": {"App\\\\": "app/"}}, "extra": {}}')
            ->and(is_file($root.'/apps/Payments/composer.json'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('deletes a module: its folder and its declaration', function () {
    $root = sys_get_temp_dir().'/distributable-delete-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 3).'/config/distributable.php', $root.'/config/distributable.php');
    File::put($root.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($root);
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.modules.path', 'apps');
    config()->set('distributable.foundation.path', 'foundation');

    try {
        $this->artisan('modules:make payments')->assertSuccessful();
        $this->artisan('modules:make messaging')->assertSuccessful();
        config()->set('distributable.declared', ['payments' => [], 'messaging' => []]);
        $this->app->forgetInstance(ModuleRegistry::class);

        $this->artisan('modules:delete payments --force')->assertSuccessful();

        expect(is_dir($root.'/apps/Payments'))->toBeFalse()
            ->and(is_dir($root.'/apps/Messaging'))->toBeTrue()
            ->and((require $root.'/config/distributable.php')['declared'])->toBe(['messaging' => []]);
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

it('reports the problems a transport names', function () {
    app(TransportManager::class)->extend('ailing', fn () => new class implements ReportsRpcProblems, Transport
    {
        public function invoke(string $module, string $name, int $version, array $payload): mixed
        {
            return null;
        }

        public function problems(): array
        {
            return ['its calls cannot be signed'];
        }
    });
    config()->set('distributable.rpc.transports.ailing', ['driver' => 'ailing']);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('its calls cannot be signed')
        ->assertFailed();
});

it('fails on two modules naming one stream differently, and leaves what each keeps for itself alone', function () {
    $file = dirname(__DIR__, 2).'/Fixtures/apps/Analytics/config/distributable.php';
    file_put_contents($file, "<?php\n\nreturn ['events' => ['streams' => ['audit' => ['driver' => 'null']]]];\n");
    $private = dirname(__DIR__, 2).'/Fixtures/apps/Analytics/config/iam.php';
    file_put_contents($private, "<?php\n\nreturn ['flag' => false, 'items' => ['from-analytics'], 'nested' => ['override' => 'analytics']];\n");

    try {
        $this->artisan('modules:doctor')
            ->expectsOutputToContain('Modules [analytics, iam] set config [distributable.events.streams.audit.driver] to different values')
            ->doesntExpectOutputToContain('[iam.')
            ->assertFailed();
    } finally {
        unlink($file);
        unlink($private);
    }
});

it('fails on a module keeping two copies in one table', function () {
    config()->set('distributable.shadows.analytics', [UserShadow::class, IamUserShadow::class]);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('[analytics] keeps two copies in table [iam_users]')
        ->assertFailed();
});

it('fails on a copy naming a folder without any migration', function () {
    config()->set('distributable.shadows.analytics', [UserShadow::class, MisplacedShadow::class]);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('[analytics] keeps '.MisplacedShadow::class.', whose migrations [')
        ->doesntExpectOutputToContain('keeps '.UserShadow::class)
        ->assertFailed();
});

it('runs the migrations a copy names in the database of the module keeping it', function () {
    expect($this->inModule('analytics', fn () => app('migrator')->paths()))
        ->toContain(dirname(__DIR__, 2).'/Fixtures/foundation/Iam/Shadows/../database/shadows/users');
});

it('passes once every module can run', function () {
    $this->artisan('modules:doctor')->expectsOutputToContain('Every module can run.')->assertSuccessful();
});
