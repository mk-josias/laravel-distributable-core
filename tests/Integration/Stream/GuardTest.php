<?php

declare(strict_types=1);

use Apps\Iam\Events\UserRegistered;
use Apps\Iam\Handlers\OnUserRegistered;
use Distributable\Providers\DistributableServiceProvider;
use Distributable\Services\Stream\Dispatcher;
use Distributable\Services\Stream\EnvelopeFactory;
use Distributable\Tests\Doubles\Recorder;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    $this->app->singleton(Recorder::class);
    Config::set('distributable.events.guard', true);

    $this->inModule('iam', function (): void {
        foreach (glob(dirname((string) (new ReflectionClass(DistributableServiceProvider::class))->getFileName(), 3).'/database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    });
});

it('runs a handler once and turns the redelivery into a no-op', function () {
    $envelope = app(EnvelopeFactory::class)->for(new UserRegistered(5, 'ada'), 'iam');
    $dispatcher = $this->app->make(Dispatcher::class);

    $dispatcher->dispatch($envelope);
    $dispatcher->dispatch($envelope); // the broker hands the same entry over again

    expect($this->app->make(Recorder::class)->records)->toHaveCount(1)
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('event_consumptions')->count())->toBe(1);
});

it('marks per handler, not per event', function () {
    Config::set('distributable.events.listen', [
        'iam.user.registered' => [OnUserRegistered::class, OnUserRegistered::class],
    ]);

    $this->app->make(Dispatcher::class)->dispatch(app(EnvelopeFactory::class)->for(new UserRegistered(5, 'ada'), 'iam'));

    // Same handler class twice in the map is one guarded slot: the second is already claimed.
    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('event_consumptions')->count())->toBe(1);
});

it('leaves no mark when the handler fails, so the event is replayed', function () {
    $this->app->bind(OnUserRegistered::class, fn () => throw new RuntimeException('handler blew up'));

    $envelope = app(EnvelopeFactory::class)->for(new UserRegistered(5, 'ada'), 'iam');

    expect(fn () => $this->app->make(Dispatcher::class)->dispatch($envelope))
        ->toThrow(RuntimeException::class);

    expect($this->inModule('iam', fn () => DB::connection('iam'))->table('event_consumptions')->count())->toBe(0);
});

it('stays off when delivery is exactly-once', function () {
    Config::set('distributable.events.guard', null);
    Config::set('distributable.events.reliable_publish', false);
    Config::set('distributable.events.streams.default.driver', 'array');

    expect($this->app->make(Dispatcher::class)->guarded())->toBeFalse();
});

it('turns itself on when events are published reliably', function () {
    Config::set('distributable.events.guard', null);
    Config::set('distributable.events.reliable_publish', true);

    expect($this->app->make(Dispatcher::class)->guarded())->toBeTrue();
});
