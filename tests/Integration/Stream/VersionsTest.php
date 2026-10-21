<?php

declare(strict_types=1);

use Apps\Iam\Events\UserRegistered;
use Apps\Iam\Events\UserRenamed;
use Distributable\Contracts\Stream\Bus;
use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Providers\DistributableServiceProvider;
use Distributable\Services\Stream\Dispatcher;
use Distributable\Services\Stream\EnvelopeFactory;
use Distributable\Services\Stream\Outbox\Relay;
use Distributable\Services\Stream\PayloadVersions;
use Distributable\Services\Stream\TransportManager;
use Distributable\Tests\Doubles\Recorder;
use Distributable\Tests\Doubles\RecordingTransport;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Support\Facades\Config;

uses(ModuleAppTestCase::class);

beforeEach(fn () => $this->app->singleton(Recorder::class));

function renamed(int $version, array $payload): Envelope
{
    return new Envelope('00000000-0000-0000-0000-00000000000'.$version, 'iam', 'iam.user.renamed', $payload, [], now()->toImmutable(), 'default', version: $version);
}

it('carries the version of the payload in the envelope, and reads an envelope without one as version 1', function () {
    $envelopes = $this->app->make(EnvelopeFactory::class);
    $envelope = $envelopes->for(new UserRenamed(5, 'Ada Lovelace'), 'iam');
    $legacy = $envelope->toArray();
    unset($legacy['version']);

    expect($envelope->version)->toBe(3)
        ->and(Envelope::fromJson($envelope->toJson())->version)->toBe(3)
        ->and($envelopes->for(new UserRegistered(5, 'lamp'), 'iam')->version)->toBe(1)
        ->and(Envelope::fromArray($legacy)->version)->toBe(1);
});

it('lifts an old payload one version at a time, so the handler only sees the current shape', function () {
    $dispatcher = $this->app->make(Dispatcher::class);

    $dispatcher->dispatch(renamed(1, ['id' => 5, 'name' => 'Ada']));
    $dispatcher->dispatch(renamed(2, ['id' => 6, 'full_name' => 'Grace']));
    $dispatcher->dispatch(renamed(3, ['id' => 7, 'full_name' => 'Linus', 'locale' => 'fi']));

    expect(array_column($this->app->make(Recorder::class)->records, 1))->toBe([
        ['id' => 5, 'full_name' => 'Ada', 'locale' => 'en'],
        ['id' => 6, 'full_name' => 'Grace', 'locale' => 'en'],
        ['id' => 7, 'full_name' => 'Linus', 'locale' => 'fi'],
    ]);
});

it('refuses an envelope newer than this process reads, and runs no handler', function () {
    try {
        $this->app->make(Dispatcher::class)->dispatch(renamed(4, ['id' => 5]));
    } catch (ModuleException $exception) {
        expect($exception->getMessage())->toBe('Event [iam.user.renamed] arrived in version 4, and this process reads it up to version 3: deploy its consumer before it is handled.')
            ->and($this->app->make(Recorder::class)->records)->toBe([]);

        return;
    }

    $this->fail('The envelope was handled.');
});

it('reads an event nobody declared a version for as version 1 only', function () {
    $versions = $this->app->make(PayloadVersions::class);

    expect($versions->current('iam.user.registered'))->toBe(1)
        ->and($versions->current('iam.user.renamed'))->toBe(3);

    $this->app->make(Dispatcher::class)->dispatch(
        new Envelope('00000000-0000-0000-0000-000000000009', 'iam', 'iam.user.registered', ['id' => 5], [], now()->toImmutable(), 'default', version: 2),
    );
})->throws(ModuleException::class, 'arrived in version 2, and this process reads it up to version 1');

it('refuses a payload class that declares no version', function () {
    $this->app->make(PayloadVersions::class)->add(['iam.user.registered' => Recorder::class]);
})->throws(ConfigurationException::class, 'must implement Distributable\Contracts\Stream\Versioned');

it('keeps the version through the publications table, up to the sent envelope', function () {
    Config::set('distributable.events.reliable_publish', true);
    Config::set('distributable.events.streams.default.driver', 'recording');
    $this->app->instance(RecordingTransport::class, new RecordingTransport);
    $this->app->make(TransportManager::class)->extend('recording', fn ($app): Transport => $app->make(RecordingTransport::class));
    $this->inModule('iam', function (): void {
        foreach (glob(dirname((string) (new ReflectionClass(DistributableServiceProvider::class))->getFileName(), 3).'/database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    });

    $this->app->make(Bus::class)->emit(new UserRenamed(5, 'Ada Lovelace'));
    $this->app->make(Relay::class)->drain('iam');

    expect($this->app->make(RecordingTransport::class)->published[0]->version)->toBe(3);
});
