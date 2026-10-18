<?php

declare(strict_types=1);

use Distributable\Config\Services;
use Distributable\Tests\OrdersAppTestCase;

uses(OrdersAppTestCase::class);

it('falls back to the application key for the RPC secret', function () {
    putenv('MICROSERVICES_RPC_SECRET');
    putenv('APP_KEY=app-key-value');

    try {
        expect((require dirname(__DIR__, 3).'/config/microservices.php')['rpc']['secret'])->toBe('app-key-value');
    } finally {
        putenv('APP_KEY');
    }
});

it('declares this service first, then the ones it calls', function () {
    expect(app(Services::class)->getDeclared())->toBe(['orders', 'billing']);
});
