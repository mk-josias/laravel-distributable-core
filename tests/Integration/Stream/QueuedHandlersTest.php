<?php

declare(strict_types=1);

use Apps\Iam\Events\UserRegistered;
use Apps\Iam\Handlers\QueuedOnUserRegistered;
use Apps\Iam\Handlers\RetriedOnUserRegistered;
use Distributable\Data\Envelope;
use Distributable\Providers\DistributableServiceProvider;
use Distributable\Services\Stream\Dispatcher;
use Distributable\Services\Stream\EnvelopeFactory;
use Distributable\Tests\Doubles\Recorder;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    $this->app->singleton(Recorder::class);
});

function registered(): Envelope
{
    return app(EnvelopeFactory::class)->for(new UserRegistered(5, 'lamp'), 'iam');
}

it('queues a handler that implements ShouldQueue, as Laravel queues a listener, and the worker runs it in its module', function () {
    Config::set('distributable.events.listen', ['iam.user.registered' => [QueuedOnUserRegistered::class]]);
    $queued = [];
    Queue::before(function (JobProcessing $event) use (&$queued): void {
        $queued[] = [$event->job->resolveName(), $event->job->maxTries()];
    });

    $this->app->make(Dispatcher::class)->dispatch(registered());

    expect($queued)->toBe([[QueuedOnUserRegistered::class, 3]])
        ->and($this->app->make(Recorder::class)->records)->toBe([['iam.user.registered', ['id' => 5, 'name' => 'lamp']]])
        ->and($this->app->make(Recorder::class)->connections)->toBe(['iam']);
});

it('gives a queued handler its job, as Laravel gives one to a queued listener, so release() and attempts() work', function () {
    Config::set('distributable.events.listen', ['iam.user.registered' => [RetriedOnUserRegistered::class]]);

    $this->app->make(Dispatcher::class)->dispatch(registered());

    expect($this->app->make(Recorder::class)->traces)->toBe([RetriedOnUserRegistered::class]);
});

it('guards a queued handler as one that runs now', function () {
    Config::set('distributable.events.listen', ['iam.user.registered' => [QueuedOnUserRegistered::class]]);
    Config::set('distributable.events.guard', true);
    $this->inModule('iam', function (): void {
        foreach (glob(dirname((string) (new ReflectionClass(DistributableServiceProvider::class))->getFileName(), 3).'/database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    });
    $envelope = registered();
    $jobs = 0;
    Queue::before(function () use (&$jobs): void {
        $jobs++;
    });

    $this->app->make(Dispatcher::class)->dispatch($envelope);
    $this->app->make(Dispatcher::class)->dispatch($envelope);

    expect($jobs)->toBe(2)
        ->and($this->app->make(Recorder::class)->records)->toHaveCount(1)
        ->and($this->inModule('iam', fn () => DB::connection('iam'))->table('event_consumptions')->count())->toBe(1);
});
