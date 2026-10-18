<?php

declare(strict_types=1);

use Apps\Iam\Events\UserRegistered;
use Distributable\Contracts\Stream\Bus;
use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Envelope;
use Distributable\Providers\DistributableServiceProvider;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StreamQueue\QueueTransport;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Config::set('queue.connections.distributable', [
        'driver' => 'database',
        'connection' => 'iam',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    Config::set('distributable.events.streams.default', ['driver' => 'queue', 'connection' => 'distributable']);

    Schema::connection('iam')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

it('fans an envelope out to one queue per declared module, database or not', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'ada'));

    expect(DB::connection('iam')->table('jobs')->orderBy('queue')->pluck('queue')->all())
        ->toBe(['distributable-default-analytics', 'distributable-default-gateway', 'distributable-default-iam']);
});

it('runs the shipped default stream on the application default queue connection once its driver is queue', function () {
    $shipped = (require dirname((string) (new ReflectionClass(DistributableServiceProvider::class))->getFileName(), 3).'/config/distributable.php')['events']['streams']['default'];
    Config::set('distributable.events.streams.default', [...$shipped, 'driver' => 'queue']);
    Config::set('queue.default', 'distributable');

    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'ada'));

    expect(DB::connection('iam')->table('jobs')->orderBy('queue')->pluck('queue')->all())
        ->toBe(['distributable:events-analytics', 'distributable:events-gateway', 'distributable:events-iam']);
});

it('hands the consumer its own copy, then deletes it', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'ada'));

    /** @var QueueTransport $transport */
    $transport = $this->app->make(Transport::class);
    $received = [];

    $transport->consume(['iam'], ['iam'], function (Envelope $envelope) use (&$received, $transport): void {
        $received[] = $envelope->payload;
        $transport->stop();
    });

    expect($received)->toBe([['id' => 5, 'name' => 'ada']])
        ->and(DB::connection('iam')->table('jobs')->pluck('queue')->all())->toBe(['distributable-default-analytics', 'distributable-default-gateway']);
});
