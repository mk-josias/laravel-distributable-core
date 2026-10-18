<?php

declare(strict_types=1);

use Apps\Analytics\Reports\UsesConnection;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleRegistry;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Support\Facades\DB;

uses(ModuleAppTestCase::class);

it('lists the connections a module declares: its own and each one of its config/database.php', function () {
    expect(app(ModuleRegistry::class)->get('analytics')->connections())->toBe(['analytics', 'warehouse'])
        ->and(app(ModuleRegistry::class)->get('iam')->connections())->toBe(['iam']);
});

it('lets a module open each connection it declares', function () {
    expect((new UsesConnection)->open('analytics'))->toBe('analytics')
        ->and((new UsesConnection)->open('warehouse'))->toBe('warehouse');
});

it('refuses a module a connection it does not declare, another module\'s included', function () {
    (new UsesConnection)->open('iam');
})->throws(ModuleException::class, 'uses connection [iam], which module [analytics] does not declare');

it('leaves the connections alone for code outside every module', function () {
    expect(DB::connection('iam')->getName())->toBe('iam');
});
