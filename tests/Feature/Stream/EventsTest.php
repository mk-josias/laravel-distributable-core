<?php

declare(strict_types=1);

use Distributable\Contracts\Stream\Bus;
use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Providers\DistributableServiceProvider;
use Distributable\Services\Stream\Dispatcher;
use Distributable\Services\Stream\TransportManager;
use Distributable\Tests\Doubles\FlakyTransport;
use Distributable\Tests\Fixtures\Orders\Events\OrderPlaced;
use Distributable\Tests\Fixtures\Orders\Handlers\OnInvoicePaid;
use Distributable\Tests\OrdersAppTestCase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

uses(OrdersAppTestCase::class);

it('stamps an event with this service as its emitter, and the propagated context', function () {
    config()->set('distributable.events.propagate', ['trace_id']);
    Context::add('trace_id', 'abc');

    app(Bus::class)->emit(new OrderPlaced(7));

    $envelope = app(Transport::class)->published()[0];

    expect($envelope)->toBeInstanceOf(Envelope::class)
        ->and($envelope->emitter)->toBe('orders')
        ->and($envelope->name)->toBe('orders.order.placed')
        ->and($envelope->payload)->toBe(['id' => 7])
        ->and($envelope->headers)->toBe(['trace_id' => 'abc']);
});

it('runs the handlers listening to an event another module emitted', function () {
    config()->set('distributable.events.listen', ['billing.invoice.paid' => [OnInvoicePaid::class]]);

    $this->receive('orders', 'billing.invoice.paid', ['order_id' => 7]);

    expect(DB::table('paid_orders')->pluck('order_id')->all())->toBe([7]);
});

it('publishes right after the commit and writes no row when events are not published reliably', function () {
    DB::transaction(function (): void {
        app(Bus::class)->emit(new OrderPlaced(7));

        expect(app(Transport::class)->published())->toBe([]);
    });

    expect(app(Transport::class)->published())->toHaveCount(1)
        ->and(DB::table('event_publications')->count())->toBe(0);
});

it('publishes nothing when the transaction rolls back and events are not written in it', function () {
    try {
        DB::transaction(function (): void {
            app(Bus::class)->emit(new OrderPlaced(7));

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(app(Transport::class)->published())->toBe([]);
});

it('writes the event in the publications table with the business transaction, and deletes the row once it is sent after the commit', function () {
    config()->set('distributable.events.reliable_publish', true);

    DB::transaction(function (): void {
        app(Bus::class)->emit(new OrderPlaced(7));

        expect(app(Transport::class)->published())->toBe([])
            ->and(DB::table('event_publications')->where('emitter', 'orders')->count())->toBe(1);
    });

    expect(app(Transport::class)->published()[0]->name)->toBe('orders.order.placed')
        ->and(DB::table('event_publications')->count())->toBe(0);
});

it('keeps the row when the sending after the commit fails, and sends it on demand', function () {
    $flaky = new FlakyTransport;
    app(TransportManager::class)->extend('flaky', fn (): Transport => $flaky);
    config()->set('distributable.events.reliable_publish', true);
    config()->set('distributable.events.streams.default.driver', 'flaky');

    DB::transaction(fn () => app(Bus::class)->emit(new OrderPlaced(7)));

    $row = DB::table('event_publications')->first();

    expect((int) $row->attempts)->toBe(1)
        ->and($row->last_error)->toContain('broker down');

    $flaky->down = false;
    $this->artisan('stream:republish')->assertSuccessful();

    expect(DB::table('event_publications')->count())->toBe(0)
        ->and($flaky->published)->toHaveCount(1);
});

it('keeps the publications and the consumption marks in the tables distributable.events.tables names', function () {
    $flaky = new FlakyTransport;
    app(TransportManager::class)->extend('flaky', fn (): Transport => $flaky);
    config()->set('distributable.events.streams.default.driver', 'flaky');
    config()->set('distributable.events.tables', ['publications' => 'outbox', 'consumptions' => 'inbox']);
    $migrations = dirname((string) (new ReflectionClass(DistributableServiceProvider::class))->getFileName(), 3).'/database/migrations';
    foreach (['0001_01_01_000000_create_event_publications_table', '0001_01_01_000001_create_event_consumptions_table', '0001_01_01_000002_add_version_to_event_publications_table'] as $file) {
        (require "{$migrations}/{$file}.php")->up();
    }
    config()->set('distributable.events.reliable_publish', true);
    config()->set('distributable.events.guard', true);
    config()->set('distributable.events.listen', ['billing.invoice.paid' => [OnInvoicePaid::class]]);

    app(Bus::class)->emit(new OrderPlaced(7));
    app(Dispatcher::class)->dispatch(new Envelope('0191f3c2-8a41-7c2e-9b55-3f1c7d0a4e90', 'billing', 'billing.invoice.paid', ['order_id' => 7], [], now()->toImmutable(), 'default'), 'orders');

    expect(DB::table('outbox')->count())->toBe(1)
        ->and(DB::table('inbox')->count())->toBe(1)
        ->and(DB::table('event_publications')->count())->toBe(0);
});

it('handles a redelivered event once', function () {
    config()->set('distributable.events.guard', true);
    config()->set('distributable.events.listen', ['billing.invoice.paid' => [OnInvoicePaid::class]]);

    $envelope = new Envelope('0191f3c2-8a41-7c2e-9b55-3f1c7d0a4e88', 'billing', 'billing.invoice.paid', ['order_id' => 7], [], now()->toImmutable(), 'default');

    app(Dispatcher::class)->dispatch($envelope, 'orders');
    app(Dispatcher::class)->dispatch($envelope, 'orders');

    expect(DB::table('paid_orders')->count())->toBe(1)
        ->and(DB::table('event_consumptions')->count())->toBe(1);
});

it('names the package of a shipped driver that is not installed', function (string $driver) {
    config()->set('distributable.events.streams.default.driver', $driver);

    expect(fn () => app(Transport::class))->toThrow(ConfigurationException::class, "composer require mk-josias/laravel-distributable-stream-{$driver}");
})->with(['redis', 'in-process']);

it('consumes the stream as this service', function () {
    config()->set('distributable.events.listen', ['billing.invoice.paid' => [OnInvoicePaid::class]]);
    app(Transport::class)->publish(new Envelope('0191f3c2-8a41-7c2e-9b55-3f1c7d0a4e89', 'billing', 'billing.invoice.paid', ['order_id' => 9], [], now()->toImmutable(), 'default'));

    $this->artisan('stream:consume')->expectsOutputToContain('Consuming as [orders] from: orders, billing')->assertSuccessful();

    expect(DB::table('paid_orders')->pluck('order_id')->all())->toBe([9]);
});
