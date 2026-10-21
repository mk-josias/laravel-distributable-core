<?php

declare(strict_types=1);

use Distributable\Context\ModuleContext;
use Distributable\Exceptions\ModuleException;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(ModuleAppTestCase::class);

it('migrates the database of each module named, on its connection, and no other database', function () {
    $this->artisan('migrate --module=iam --module=analytics')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('iam_users'))->toBeTrue()
        ->and($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('event_consumptions'))->toBeTrue()
        ->and($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('migrations'))->toBeTrue()
        ->and($this->inModule('analytics', fn () => Schema::connection('analytics'))->hasTable('migrations'))->toBeTrue()
        ->and(config('database.default'))->toBe(ModuleContext::NO_MODULE);
});

it('creates the publications table in a module database only when events are published reliably', function () {
    $this->artisan('migrate --module=iam')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('event_publications'))->toBeFalse();

    config()->set('distributable.events.reliable_publish', true);
    $this->artisan('migrate --module=iam')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('event_publications'))->toBeTrue();
});

it('creates no consumption table once the guard is switched off', function () {
    config()->set('distributable.events.guard', false);
    $this->artisan('migrate --module=iam')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('event_consumptions'))->toBeFalse();
});

it('runs the migrations a third-party package loads in each module database', function () {
    app('migrator')->path(dirname(__DIR__, 2).'/Fixtures/packages/acme/migrations');

    $this->artisan('migrate --module=iam --module=analytics')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('acme_things'))->toBeTrue()
        ->and($this->inModule('analytics', fn () => Schema::connection('analytics'))->hasTable('acme_things'))->toBeTrue();
});

it('runs a folder a module provider loads in that module database only', function () {
    $this->artisan('migrate --module=iam --module=analytics')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('iam_extra'))->toBeTrue()
        ->and($this->inModule('analytics', fn () => Schema::connection('analytics'))->hasTable('iam_extra'))->toBeFalse()
        ->and(app('migrator')->paths())->not->toContain(dirname(__DIR__, 2).'/Fixtures/apps/Iam/database/extra');
});

it('limits a run to the modules named', function () {
    $this->artisan('migrate --module=iam')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('iam_users'))->toBeTrue()
        ->and($this->inModule('analytics', fn () => Schema::connection('analytics'))->hasTable('migrations'))->toBeFalse();
});

it('rolls a module database back with the plain Laravel command', function () {
    $this->artisan('migrate --module=iam')->assertSuccessful();
    $this->artisan('migrate:rollback --module=iam')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('iam_users'))->toBeFalse()
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('migrations')->count())->toBe(0);
});

it('wipes and rebuilds a module database with migrate:fresh', function () {
    $this->artisan('migrate --module=iam')->assertSuccessful();
    $this->inModule('iam', fn () => DB::connection('iam'))->table('iam_users')->insert(['name' => 'gone']);

    $this->artisan('migrate:fresh --module=iam')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('iam_users'))->toBeTrue()
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('iam_users')->count())->toBe(0);
});

it('reports each database from migrate:status', function () {
    $this->artisan('migrate --module=iam --module=analytics')->assertSuccessful();

    $this->artisan('migrate:status --module=iam --module=analytics')->expectsOutputToContain('Module [iam]')->assertSuccessful();
});

it('keeps an explicit --database the plain Laravel command, in the module named', function () {
    $this->artisan('migrate --module=iam --database=iam --path='.dirname(__DIR__, 2).'/Fixtures/apps/Iam/database/migrations --realpath')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('iam_users'))->toBeTrue()
        ->and($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('event_publications'))->toBeFalse();
});

it('migrates nothing outside every module, as any query there', function () {
    expect(fn () => $this->artisan('migrate'))->toThrow(ModuleException::class, 'No module runs here');
});

it('rolls nothing back outside every module, as any query there', function () {
    $this->artisan('migrate --module=iam --module=analytics')->assertSuccessful();

    expect(fn () => $this->artisan('migrate:rollback'))->toThrow(ModuleException::class, 'No module runs here')
        ->and($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('iam_users'))->toBeTrue()
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('migrations')->count())->toBeGreaterThan(0);
});

it('rolls back each module named', function () {
    $this->artisan('migrate --module=iam --module=analytics')->assertSuccessful();

    $this->artisan('migrate:rollback --module=iam --module=analytics')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('iam_users'))->toBeFalse()
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('migrations')->count())->toBe(0)
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('migrations')->count())->toBe(0);
});

it('migrates each module on the connection --database names, which each module declares for itself', function () {
    $files = [];

    foreach (['iam', 'analytics'] as $module) {
        $files[$module] = (string) tempnam(sys_get_temp_dir(), "distributable-owner-{$module}-");
        $this->inModule($module, static fn () => config()->set('database.connections.owner', ['driver' => 'sqlite', 'database' => $files[$module], 'prefix' => '']));
    }

    $this->artisan('migrate --module=iam --module=analytics --database=owner')->assertSuccessful();

    expect($this->inModule('iam', fn () => Schema::connection('owner'))->hasTable('iam_users'))->toBeTrue()
        ->and($this->inModule('analytics', fn () => Schema::connection('owner'))->hasTable('iam_users'))->toBeTrue()
        ->and($this->inModule('analytics', fn () => Schema::connection('owner'))->hasTable('iam_extra'))->toBeFalse()
        ->and($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('migrations'))->toBeFalse();
});

it('knows no module connection outside the module, as Laravel knows no connection it was not given', function () {
    expect(fn () => $this->artisan('migrate --database=iam'))
        ->toThrow(InvalidArgumentException::class, 'Database connection [iam] not configured.');

    expect($this->inModule('iam', fn () => Schema::connection('iam'))->hasTable('migrations'))->toBeFalse();
});
