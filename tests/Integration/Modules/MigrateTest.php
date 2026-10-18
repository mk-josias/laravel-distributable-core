<?php

declare(strict_types=1);

use Distributable\Context\ModuleContext;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(ModuleAppTestCase::class);

it('migrates each local module database on its owner connection, and no other database', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('iam_users'))->toBeTrue()
        ->and(Schema::connection('iam')->hasTable('event_consumptions'))->toBeTrue()
        ->and(Schema::connection('iam')->hasTable('migrations'))->toBeTrue()
        ->and(Schema::connection('analytics')->hasTable('migrations'))->toBeTrue()
        ->and(config('database.default'))->toBe(ModuleContext::NO_MODULE);
});

it('creates the publications table in a module database only when events are published reliably', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('event_publications'))->toBeFalse();

    config()->set('distributable.events.reliable_publish', true);
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('event_publications'))->toBeTrue();
});

it('creates no consumption table once the guard is switched off', function () {
    config()->set('distributable.events.guard', false);
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('event_consumptions'))->toBeFalse();
});

it('runs the migrations a third-party package loads in each module database', function () {
    app('migrator')->path(dirname(__DIR__, 2).'/Fixtures/packages/acme/migrations');

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('acme_things'))->toBeTrue()
        ->and(Schema::connection('analytics')->hasTable('acme_things'))->toBeTrue();
});

it('limits a run to the modules named', function () {
    $this->artisan('migrate --module=iam')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('iam_users'))->toBeTrue()
        ->and(Schema::connection('analytics')->hasTable('migrations'))->toBeFalse();
});

it('rolls a module database back with the plain Laravel command', function () {
    $this->artisan('migrate')->assertSuccessful();
    $this->artisan('migrate:rollback --module=iam')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('iam_users'))->toBeFalse()
        ->and(DB::connection('iam')->table('migrations')->count())->toBe(0);
});

it('wipes and rebuilds a module database with migrate:fresh', function () {
    $this->artisan('migrate')->assertSuccessful();
    DB::connection('iam')->table('iam_users')->insert(['name' => 'gone']);

    $this->artisan('migrate:fresh --module=iam')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('iam_users'))->toBeTrue()
        ->and(DB::connection('iam')->table('iam_users')->count())->toBe(0);
});

it('reports each database from migrate:status', function () {
    $this->artisan('migrate')->assertSuccessful();

    $this->artisan('migrate:status')->expectsOutputToContain('Module [iam]')->assertSuccessful();
});

it('keeps an explicit --database the plain Laravel command', function () {
    $this->artisan('migrate --database=iam --path='.dirname(__DIR__, 2).'/Fixtures/apps/Iam/database/migrations --realpath')->assertSuccessful();

    expect(Schema::connection('iam')->hasTable('iam_users'))->toBeTrue()
        ->and(Schema::connection('iam')->hasTable('event_publications'))->toBeFalse();
});
