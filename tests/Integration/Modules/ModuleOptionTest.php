<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Distributable\Context\ModuleContext;
use Distributable\Exceptions\ModuleException;
use Distributable\Tests\Doubles\DefaultConnectionCommand;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(ModuleAppTestCase::class);

function iamUsersTable(): void
{
    app(ModuleContext::class)->connection('iam')->getSchemaBuilder()->create('iam_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
}

it('runs any command in the module --module names, and in no module without it', function () {
    Artisan::registerCommand($this->app->make(DefaultConnectionCommand::class));

    $this->artisan('probe:connection --module=iam')->expectsOutput('iam')->assertSuccessful();
    $this->artisan('probe:connection')->expectsOutput(ModuleContext::NO_MODULE)->assertSuccessful();
});

it('runs any command once in each module --module names, one after the other', function () {
    Artisan::registerCommand($this->app->make(DefaultConnectionCommand::class));

    $this->artisan('probe:connection --module=iam --module=analytics')
        ->expectsOutputToContain('Module [iam]')
        ->expectsOutput('iam')
        ->expectsOutputToContain('Module [analytics]')
        ->expectsOutput('analytics')
        ->assertSuccessful();

    expect(DB::getDefaultConnection())->toBe(ModuleContext::NO_MODULE);
});

it('runs any command once in every module the process runs with --module=*', function () {
    Artisan::registerCommand($this->app->make(DefaultConnectionCommand::class));

    $this->artisan('probe:connection --module=*')
        ->expectsOutputToContain('Module [analytics]')
        ->expectsOutput('analytics')
        ->expectsOutputToContain('Module [gateway]')
        ->expectsOutput(ModuleContext::NO_MODULE)
        ->expectsOutputToContain('Module [iam]')
        ->expectsOutput('iam')
        ->assertSuccessful();
});

it('runs a command a module declares in that module, without --module', function () {
    $this->artisan('analytics:connection')->expectsOutput('analytics')->assertSuccessful();
});

it('prunes the consumption marks of the module --module names only, of every local module without it', function () {
    foreach (['iam', 'analytics'] as $module) {
        $this->inModule($module, static function () use ($module): void {
            config()->set("database.connections.{$module}.database", tempnam(sys_get_temp_dir(), "distributable-{$module}-"));
            DB::purge($module);
        });
    }

    $this->artisan('migrate --module=iam --module=analytics')->assertSuccessful();

    foreach (['iam', 'analytics'] as $module) {
        $this->inModule($module, fn () => DB::connection($module))->table('event_consumptions')->insert(['event_id' => (string) str()->uuid7(), 'handler' => 'Old', 'consumed_at' => now()->subDays(10)]);
    }

    $this->artisan('stream:prune-consumptions --module=analytics')->expectsOutput('→ analytics: 1 deleted')->assertSuccessful();

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('event_consumptions')->count())->toBe(1)
        ->and($this->inModule('analytics', fn () => DB::connection('analytics'))->table('event_consumptions')->count())->toBe(0);

    $this->artisan('stream:prune-consumptions')->expectsOutput('→ iam: 1 deleted')->assertSuccessful();
});

it('seeds a module with its own DatabaseSeeder, or the seeder of its own it is asked for', function () {
    iamUsersTable();

    $this->artisan('db:seed --module=iam')->assertSuccessful();
    $this->artisan('db:seed --module=iam --class=AdminSeeder')->assertSuccessful();

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('iam_users')->pluck('name')->all())->toBe(['seeded', 'admin']);
});

it('seeds a module with the application DatabaseSeeder, then its own, as migrate runs both', function () {
    require_once dirname(__DIR__, 2).'/Fixtures/database/seeders/DatabaseSeeder.php';
    DatabaseSeeder::$ran = [];
    iamUsersTable();

    $this->artisan('db:seed --module=iam')->assertSuccessful();
    $this->artisan('db:seed --module=analytics')->assertSuccessful();

    expect(DatabaseSeeder::$ran)->toBe(['iam', 'analytics'])
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('iam_users')->pluck('name')->all())->toBe(['seeded']);
});

it('seeds each module with its own seeders when a migration run is asked to seed', function () {
    $this->artisan('migrate:fresh --seed --module=iam --module=analytics')->assertSuccessful();

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('iam_users')->pluck('name')->all())->toBe(['seeded']);
});

it('refuses a module nobody declared', function () {
    $this->artisan('db:seed --module=ghost');
})->throws(ModuleException::class, 'Unknown module [ghost]');

it('offers --module on every command but make:provider', function () {
    $offers = fn (string $command): bool => Artisan::all()[$command]->getDefinition()->hasOption('module');

    expect($offers('db:seed'))->toBeTrue()
        ->and($offers('queue:work'))->toBeTrue()
        ->and($offers('migrate'))->toBeTrue()
        ->and($offers('make:provider'))->toBeFalse();
});

it('reads --module=* as no module in particular', function () {
    Artisan::registerCommand($this->app->make(DefaultConnectionCommand::class));

    $this->artisan('probe:connection --module=*')->expectsOutput(ModuleContext::NO_MODULE)->assertSuccessful();
});

it('answers db:wipe outside every module as it answers a query', function () {
    expect(fn () => Artisan::call('db:wipe'))->toThrow(ModuleException::class);
});
