<?php

declare(strict_types=1);

use Apps\Analytics\Reports\UsesConnection;
use Distributable\Services\ModuleRegistry;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

uses(ModuleAppTestCase::class);

it('lists the connections a module declares: its own and each one of its config/database.php', function () {
    expect(app(ModuleRegistry::class)->get('analytics')->connections())->toBe(['analytics', 'warehouse'])
        ->and(app(ModuleRegistry::class)->get('iam')->connections())->toBe(['iam']);
});

it('lets a module open each connection it declares', function () {
    expect($this->inModule('analytics', fn () => (new UsesConnection)->open('analytics')))->toBe('analytics')
        ->and($this->inModule('analytics', fn () => (new UsesConnection)->open('warehouse')))->toBe('warehouse');
});

it('knows no module connection while no module runs, whatever class asks: the running module decides, not the namespace', function () {
    expect(fn () => (new UsesConnection)->open('analytics'))->toThrow(InvalidArgumentException::class, 'Database connection [analytics] not configured.')
        ->and(fn () => DB::connection('iam'))->toThrow(InvalidArgumentException::class, 'Database connection [iam] not configured.');
});

it('refuses a module the connection of another module, even one already opened', function () {
    $this->inModule('iam', fn () => DB::connection('iam'))->select('select 1');

    expect(fn () => $this->inModule('analytics', fn () => DB::connection('iam')))
        ->toThrow(InvalidArgumentException::class, 'Database connection [iam] not configured.')
        ->and($this->inModule('analytics', fn () => DB::connection('warehouse')->getName()))->toBe('warehouse')
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->getName())->toBe('iam');
});

it('lets every module use a connection the application defines', function () {
    config()->set('database.connections.main', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    expect($this->inModule('iam', fn () => DB::connection('main')->getName()))->toBe('main')
        ->and($this->inModule('analytics', fn () => DB::connection('main')->getName()))->toBe('main');
});
