<?php

declare(strict_types=1);

use Distributable\Contracts\Rpc\ServesRpc;
use Distributable\Contracts\Rpc\Transport;
use Distributable\Services\Rpc\TransportManager;
use Distributable\Tests\OrdersAppTestCase;

uses(OrdersAppTestCase::class);

it('starts the process of a transport that has one', function () {
    app(TransportManager::class)->extend('listening', fn () => new class implements ServesRpc, Transport
    {
        public function invoke(string $module, string $name, int $version, array $payload): mixed
        {
            return null;
        }

        public function serve(): int
        {
            return 7;
        }
    });
    config()->set('distributable.rpc.transports.listening', ['driver' => 'listening']);

    $this->artisan('rpc:serve --transport=listening')->assertExitCode(7);
});

it('refuses a transport that has no process of its own', function () {
    app(TransportManager::class)->extend('quiet', fn () => new class implements Transport
    {
        public function invoke(string $module, string $name, int $version, array $payload): mixed
        {
            return null;
        }
    });
    config()->set('distributable.rpc.transports.quiet', ['driver' => 'quiet']);

    $this->artisan('rpc:serve --transport=quiet')->expectsOutputToContain('has no process of its own')->assertFailed();
});
