<?php

declare(strict_types=1);

use Distributable\Contracts\Stream\ArchiveStore;
use Distributable\Contracts\Stream\Bus;
use Distributable\Contracts\Stream\Transport;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Services\Stream\ArchiveStores;
use Distributable\Services\Stream\TransportManager;
use Distributable\Tests\Doubles\ArchivingTransport;
use Distributable\Tests\Fixtures\Orders\Events\OrderPlaced;
use Distributable\Tests\OrdersAppTestCase;
use Illuminate\Support\Facades\Date;

uses(OrdersAppTestCase::class);

/** A stream that keeps three orders of this service, emitted on the 1st, 2nd and 3rd of September. */
function streamOfOrders(): ArchivingTransport
{
    $transport = new ArchivingTransport;
    app(TransportManager::class)->extend('archiving', fn (): Transport => $transport);
    config()->set('distributable.events.streams.default.driver', 'archiving');

    foreach ([1, 2, 3] as $id) {
        Date::setTestNow("2026-09-0{$id} 10:00:00");
        app(Bus::class)->emit(new OrderPlaced($id));
    }

    Date::setTestNow();

    return $transport;
}

it('copies only the entries emitted in the window --since and --until name, and deletes nothing', function () {
    $transport = streamOfOrders();
    $path = tempnam(sys_get_temp_dir(), 'archive');

    $this->artisan("stream:export {$path} --since=2026-09-02 --until=2026-09-03")->assertSuccessful();

    expect(count(file($path)))->toBe(1)
        ->and($transport->published)->toHaveCount(3);
});

it('copies only what every consumer acknowledged with --acknowledged', function () {
    $transport = streamOfOrders();
    $transport->acknowledged = 2;
    $path = tempnam(sys_get_temp_dir(), 'archive');

    $this->artisan("stream:export {$path} --acknowledged")->assertSuccessful();

    expect(count(file($path)))->toBe(2);
});

it('imports only the entries matching its filters, back on the stream', function () {
    $transport = streamOfOrders();
    $path = tempnam(sys_get_temp_dir(), 'archive');
    $this->artisan("stream:export {$path}")->assertSuccessful();

    $this->artisan("stream:import {$path} --emitter=orders --where=payload.id=2")->assertSuccessful();

    expect($transport->published)->toHaveCount(4)
        ->and($transport->published[3]->payload)->toBe(['id' => 2]);
});

it('keeps the entries in the store --store names, registered with extend()', function () {
    $transport = streamOfOrders();
    $memory = new class implements ArchiveStore
    {
        /** @var array<string, list<array<string, mixed>>> */
        public array $rows = [];

        public function write(string $target, array $rows): void
        {
            $this->rows[$target] = [...$this->rows[$target] ?? [], ...$rows];
        }

        public function read(string $source): iterable
        {
            return $this->rows[$source] ?? [];
        }
    };
    app(ArchiveStores::class)->extend('memory', fn () => $memory);

    $this->artisan('stream:export september --store=memory')->assertSuccessful();
    $this->artisan('stream:import september --store=memory --since=2026-09-03')->assertSuccessful();

    expect(count($memory->rows['september']))->toBe(3)
        ->and($transport->published)->toHaveCount(4);
});

it('fails on a store nobody registered', function () {
    streamOfOrders();

    $this->artisan('stream:export somewhere --store=s3');
})->throws(ConfigurationException::class, 'Archive store [s3] is not supported');

it('fails on a transport that cannot give its entries back', function () {
    $this->artisan('stream:export somewhere');
})->throws(ConfigurationException::class, 'cannot give its entries back');
