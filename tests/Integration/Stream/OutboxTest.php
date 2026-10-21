<?php

declare(strict_types=1);

use Apps\Iam\Events\UserAudited;
use Apps\Iam\Events\UserRegistered;
use Distributable\Context\ModuleContext;
use Distributable\Contracts\Stream\Bus;
use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;
use Distributable\Providers\DistributableServiceProvider;
use Distributable\Services\Stream\EnvelopeFactory;
use Distributable\Services\Stream\Outbox\Relay;
use Distributable\Services\Stream\TransportManager;
use Distributable\Tests\Doubles\ExplodingTransport;
use Distributable\Tests\Doubles\Recorder;
use Distributable\Tests\Doubles\RecordingTransport;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Config::set('distributable.events.reliable_publish', true);
    Config::set('distributable.events.streams.default.driver', 'recording');

    $this->app->instance(RecordingTransport::class, new RecordingTransport);
    $this->app->make(TransportManager::class)->extend(
        'recording',
        fn ($app): Transport => $app->make(RecordingTransport::class),
    );
    $this->app->make(TransportManager::class)->extend(
        'exploding',
        fn (): Transport => new ExplodingTransport,
    );

    $this->app->singleton(Recorder::class);

    // The package's publication migrations, on the iam module's database.
    $this->inModule('iam', function (): void {
        foreach (glob(dirname((string) (new ReflectionClass(DistributableServiceProvider::class))->getFileName(), 3).'/database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    });
});

function publications(): Builder
{
    return app(ModuleContext::class)->connection('iam')->table('event_publications');
}

it('stores the event in the module database with the transaction, and deletes the row once it is sent', function () {
    $this->inModule('iam', fn () => DB::connection('iam'))->transaction(function (): void {
        $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));

        $row = publications()->first();

        expect($row->name)->toBe('iam.user.registered')
            ->and($row->emitter)->toBe('iam')
            ->and(json_decode((string) $row->payload, true))->toBe(['id' => 5, 'name' => 'lamp'])
            ->and($this->app->make(RecordingTransport::class)->published)->toBe([]);
    });

    expect($this->app->make(RecordingTransport::class)->published)->toHaveCount(1)
        ->and(publications()->count())->toBe(0);
});

it('publishes without a row when events are not published reliably', function () {
    Config::set('distributable.events.reliable_publish', false);

    $this->app->make(Bus::class)->emit(new UserAudited(7));

    $received = [];
    $this->app->make(TransportManager::class)->stream('audit')->consume(['analytics'], ['iam'], function (Envelope $envelope) use (&$received): void {
        $received[] = $envelope->payload;
    });

    expect($received)->toBe([['id' => 7]])
        ->and(publications()->count())->toBe(0);
});

it('publishes nothing when the business transaction rolls back', function () {
    try {
        $this->inModule('iam', fn () => DB::connection('iam'))->transaction(function (): void {
            $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));

            throw new RuntimeException('business failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    // No ghost event, and no orphan row: one transaction, one outcome.
    expect(publications()->count())->toBe(0)
        ->and($this->app->make(RecordingTransport::class)->published)->toBe([]);
});

it('keeps a row when its sending fails, and sends it on demand', function () {
    Config::set('distributable.events.streams.default.driver', 'exploding');

    $this->app->make(Bus::class)->emit(new UserRegistered(1, 'first'));
    $this->app->make(Bus::class)->emit(new UserRegistered(2, 'second'));

    expect(publications()->count())->toBe(2);

    Config::set('distributable.events.streams.default.driver', 'recording');

    $sent = $this->app->make(Relay::class)->drain('iam');

    expect($sent)->toBe(2)
        ->and(array_map(fn (Envelope $e) => $e->payload['id'], $this->app->make(RecordingTransport::class)->published))->toBe([1, 2])
        ->and(publications()->count())->toBe(0);
});

it('sends the stored rows of every local module database from the command', function () {
    $envelope = app(EnvelopeFactory::class)->for(new UserRegistered(5, 'lamp'), 'iam');
    publications()->insert([
        'id' => $envelope->id,
        'emitter' => 'iam',
        'name' => $envelope->name,
        'payload' => json_encode($envelope->payload),
        'headers' => '[]',
        'emitted_at' => $envelope->emittedAt,
        'recipients' => '[]',
        'stream' => 'default',
    ]);

    $this->artisan('stream:republish --module=iam')->assertSuccessful();

    expect($this->app->make(RecordingTransport::class)->published)->toHaveCount(1)
        ->and(publications()->count())->toBe(0);
});

it('records the failure of every row it could not send, and goes on', function () {
    Config::set('distributable.events.streams.default.driver', 'exploding');

    $this->app->make(Bus::class)->emit(new UserRegistered(1, 'first'));
    $this->app->make(Bus::class)->emit(new UserRegistered(2, 'second'));

    $sent = $this->app->make(Relay::class)->drain('iam');

    [$first, $second] = publications()->orderBy('sequence')->get()->all();

    expect($sent)->toBe(0)
        ->and((int) $first->attempts)->toBe(2)
        ->and($first->last_error)->toContain('transport down')
        ->and((int) $second->attempts)->toBe(2);
});
