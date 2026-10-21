<?php

declare(strict_types=1);

use Distributable\Tests\Fixtures\AcmeServiceProvider;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Filesystem\Filesystem;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/distributable-publish-'.uniqid();
    mkdir($this->root.'/database/migrations', 0777, true);
    mkdir($this->root.'/config', 0777, true);
    $this->app->useDatabasePath($this->root.'/database');
    $this->app->useConfigPath($this->root.'/config');
    $this->app->register(AcmeServiceProvider::class);
    $this->inIam = dirname(__DIR__, 2).'/Fixtures/apps/Iam/database/migrations';
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->root);
    array_map(unlink(...), glob($this->inIam.'/*_create_acme_things_table.php') ?: []);
});

it('publishes a migration into the module --module names', function () {
    $this->artisan('vendor:publish --tag=acme-migrations --module=iam')->assertSuccessful();

    expect(glob($this->inIam.'/*_create_acme_things_table.php'))->toHaveCount(1)
        ->and(glob($this->root.'/database/migrations/*'))->toBe([]);
});

it('publishes a migration into each module --module names', function () {
    $analytics = dirname(__DIR__, 2).'/Fixtures/apps/Analytics/database';

    try {
        $this->artisan('vendor:publish --tag=acme-migrations --module=iam --module=analytics')->assertSuccessful();

        expect(glob($this->inIam.'/*_create_acme_things_table.php'))->toHaveCount(1)
            ->and(glob($analytics.'/migrations/*_create_acme_things_table.php'))->toHaveCount(1);
    } finally {
        (new Filesystem)->deleteDirectory($analytics);
    }
});

it('publishes a package config into the module --module names, as a migration', function () {
    $config = dirname(__DIR__, 2).'/Fixtures/apps/Iam/config/acme.php';

    try {
        $this->artisan('vendor:publish --tag=acme-config --module=iam')->assertSuccessful();

        expect(is_file($config))->toBeTrue()
            ->and(is_file($this->root.'/config/acme.php'))->toBeFalse();
    } finally {
        is_file($config) && unlink($config);
    }
});

it('publishes into the application without --module', function () {
    $this->artisan('vendor:publish --tag=acme-migrations')->assertSuccessful();

    expect(glob($this->root.'/database/migrations/*_create_acme_things_table.php'))->toHaveCount(1)
        ->and(glob($this->inIam.'/*_create_acme_things_table.php'))->toBe([]);
});
