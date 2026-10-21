<?php

declare(strict_types=1);

use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;
use Distributable\Events\ShadowChanged;
use Distributable\Services\Stream\Dispatcher;
use Distributable\Services\Stream\TransportManager;
use Distributable\Tests\Fixtures\Orders\Models\CustomerShadow;
use Distributable\Tests\Fixtures\Orders\Models\Order;
use Distributable\Tests\Fixtures\Orders\Models\Refund;
use Distributable\Tests\OrdersAppTestCase;
use Illuminate\Support\Facades\DB;

uses(OrdersAppTestCase::class);

function customerChanged(int $key, array $attributes): Envelope
{
    return new Envelope((string) str()->uuid7(), 'billing', ShadowChanged::NAME, ['source' => 'customers', 'key' => $key, 'attributes' => $attributes], [], now()->toImmutable(), 'default');
}

it('keeps a copy of the rows another service announces, in a table named after this service', function () {
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Ada']), 'orders');
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Grace']), 'orders');

    expect((new CustomerShadow)->getTable())->toBe('customers')
        ->and(DB::table('customers')->get()->map(fn ($row): array => (array) $row)->all())
        ->toBe([['id' => 3, 'name' => 'Grace', 'deleted_at' => null]]);
});

it('soft-deletes the copy of a row deleted at the source', function () {
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Ada']), 'orders');
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Ada', 'deleted_at' => '2026-10-03 10:00:00']), 'orders');

    expect(CustomerShadow::query()->count())->toBe(0)
        ->and(CustomerShadow::query()->withTrashed()->count())->toBe(1);
});

it('keeps only the rows its copy wants, and drops a row that stops matching', function () {
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Ada']), 'orders');
    app(Dispatcher::class)->dispatch(customerChanged(4, ['name' => 'Hidden']), 'orders');
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Hidden']), 'orders');

    expect(CustomerShadow::query()->withTrashed()->count())->toBe(0);
});

it('ignores an announcement older than the copy', function () {
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Ada']), 'orders');
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Ada', 'deleted_at' => '2026-10-03 10:00:00']), 'orders');
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Late', 'deleted_at' => '2026-10-02 10:00:00']), 'orders');

    expect(CustomerShadow::query()->withTrashed()->pluck('name')->all())->toBe(['Ada'])
        ->and(CustomerShadow::query()->count())->toBe(0);
});

it('applies an announcement that carries no date, whatever the copy holds', function () {
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Ada', 'deleted_at' => '2026-10-03 10:00:00']), 'orders');
    app(Dispatcher::class)->dispatch(customerChanged(3, ['name' => 'Grace']), 'orders');

    expect(CustomerShadow::query()->withTrashed()->pluck('name')->all())->toBe(['Grace']);
});

it('never writes the copy outside a sync', function () {
    expect((new CustomerShadow(['id' => 1, 'name' => 'Ada']))->save())->toBeFalse();
});

it('announces a source row with only its shadowed fields, as this service', function () {
    $order = Order::query()->create(['total' => 120, 'note' => 'private']);

    $envelope = app(Transport::class)->published()[0];

    expect($envelope->emitter)->toBe('orders')
        ->and($envelope->name)->toBe(ShadowChanged::NAME)
        ->and($envelope->payload)->toBe(['source' => 'orders', 'key' => $order->id, 'attributes' => ['total' => 120]]);
});

it('announces a source row on the stream its model names, not on the default one', function () {
    config()->set('distributable.events.streams.shadows.driver', 'array');

    $refund = Refund::query()->create(['amount' => 30]);

    $envelope = app(TransportManager::class)->stream('shadows')->published()[0];

    expect($envelope->stream)->toBe('shadows')
        ->and($envelope->name)->toBe(ShadowChanged::NAME)
        ->and($envelope->payload)->toBe(['source' => 'refunds', 'key' => $refund->id, 'attributes' => ['amount' => 30]])
        ->and(app(Transport::class)->published())->toBe([]);
});

it('publishes the outbox row of a source row on the stream it was announced on', function () {
    config()->set('distributable.events.streams.shadows', ['driver' => 'array']);
    config()->set('distributable.events.reliable_publish', true);

    DB::transaction(fn () => Refund::query()->create(['amount' => 30]));

    expect(DB::table('event_publications')->where('stream', 'shadows')->whereNull('published_at')->count())->toBe(0)
        ->and(app(TransportManager::class)->stream('shadows')->published())->toHaveCount(1)
        ->and(app(Transport::class)->published())->toBe([]);
});
