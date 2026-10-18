<?php

declare(strict_types=1);

use Distributable\Context\ModuleContext;
use Distributable\Exceptions\ModuleException;
use Distributable\Tests\Doubles\DefaultConnectionCommand;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(ModuleAppTestCase::class);

function iamUsersTable(string $connection): void
{
    Schema::connection($connection)->create('iam_users', function (Blueprint $table): void {
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

it('runs a command a module declares in that module, without --module', function () {
    $this->artisan('analytics:connection')->expectsOutput('analytics')->assertSuccessful();
});

it('prunes the consumption marks of the module --module names only, of every local module without it', function () {
    foreach (['iam', 'analytics'] as $module) {
        config()->set("database.connections.{$module}.database", tempnam(sys_get_temp_dir(), "distributable-{$module}-"));
        DB::purge($module);
    }

    $this->artisan('migrate')->assertSuccessful();

    foreach (['iam', 'analytics'] as $module) {
        DB::connection($module)->table('event_consumptions')->insert(['event_id' => (string) str()->uuid7(), 'handler' => 'Old', 'consumed_at' => now()->subDays(10)]);
    }

    $this->artisan('stream:prune-consumptions --module=analytics')->expectsOutput('→ analytics: 1 deleted')->assertSuccessful();

    expect(DB::connection('iam')->table('event_consumptions')->count())->toBe(1)
        ->and(DB::connection('analytics')->table('event_consumptions')->count())->toBe(0);

    $this->artisan('stream:prune-consumptions')->expectsOutput('→ iam: 1 deleted')->assertSuccessful();
});

it('seeds a module with its own DatabaseSeeder, or the seeder of its own it is asked for', function () {
    iamUsersTable('iam');

    $this->artisan('db:seed --module=iam')->assertSuccessful();
    $this->artisan('db:seed --module=iam --class=AdminSeeder')->assertSuccessful();

    expect(DB::connection('iam')->table('iam_users')->pluck('name')->all())->toBe(['seeded', 'admin']);
});

it('has nothing to seed in a module without a DatabaseSeeder', function () {
    $this->artisan('db:seed --module=analytics')
        ->expectsOutputToContain('Module [analytics] has no DatabaseSeeder: nothing to seed.')
        ->assertSuccessful();
});

it('seeds each module with its own seeders when a migration run is asked to seed', function () {
    $this->artisan('migrate:fresh --seed --module=iam --module=analytics')->assertSuccessful();

    expect(DB::connection('iam')->table('iam_users')->pluck('name')->all())->toBe(['seeded']);
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
