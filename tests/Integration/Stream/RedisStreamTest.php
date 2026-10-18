<?php

declare(strict_types=1);

use Apps\Iam\Events\UserRegistered;
use Distributable\Data\Envelope;
use Distributable\Services\Stream\TransportManager;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use StreamRedis\RedisStreamTransport;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server reachable.');
    }

    config()->set('distributable.events.streams.default.driver', 'redis');
    config()->set('distributable.events.streams.default.key', 'distributable-test:'.Str::random(8));
    config()->set('distributable.events.streams.default.block', 100);
});

afterEach(function () {
    Redis::connection()->command('del', [(string) config('distributable.events.streams.default.key')]);
});

function redisTransport(): RedisStreamTransport
{
    /** @var RedisStreamTransport */
    return app(TransportManager::class)->stream();
}

/**
 * @param  list<string>  $channels
 * @return list<int>
 */
function consumeAll(RedisStreamTransport $transport, string $consumer, int $expected, array $channels = ['iam', 'analytics']): array
{
    $seen = [];

    $transport->consume([$consumer], $channels, function (Envelope $envelope) use (&$seen, $transport, $expected): void {
        $seen[] = (int) $envelope->payload['id'];

        if (count($seen) === $expected) {
            $transport->stop();
        }
    });

    return $seen;
}

it('delivers the published envelopes in order to each consumer group', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect(consumeAll($transport, 'analytics', 2))->toBe([1, 2])
        ->and(consumeAll(redisTransport(), 'iam', 2))->toBe([1, 2])
        ->and(Redis::connection()->command('xpending', [config('distributable.events.streams.default.key'), 'analytics'])[0])->toBe(0);
});

it('keeps one order across every emitting module, in the single key of the stream', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'analytics'));
    $transport->publish(Envelope::for(new UserRegistered(3, 'c'), 'iam'));

    expect(consumeAll($transport, 'gateway', 3))->toBe([1, 2, 3])
        ->and((int) Redis::connection()->command('xlen', [config('distributable.events.streams.default.key')]))->toBe(3);
});

it('acknowledges unread the entries of an emitter the consumer does not listen to', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'analytics'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect(consumeAll($transport, 'gateway', 1, ['iam']))->toBe([2])
        ->and(Redis::connection()->command('xpending', [config('distributable.events.streams.default.key'), 'gateway'])[0])->toBe(0);
});

/** @return list<int> the ids handled, in order, until $last is; the first attempt at $failing throws */
function consumeFailingOnce(string $failing, int $last): array
{
    $transport = redisTransport();
    $seen = [];
    $failed = false;

    $transport->consume(['analytics'], ['iam'], function (Envelope $envelope) use (&$seen, &$failed, $failing, $last, $transport): void {
        $id = (int) $envelope->payload['id'];

        if ($id === (int) $failing && ! $failed) {
            $failed = true;

            throw new RuntimeException('handler failed');
        }

        $seen[] = $id;

        if ($id === $last) {
            $transport->stop();
        }
    });

    return $seen;
}

it('retries a failed entry before any later one when the stream blocks on failure', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect(consumeFailingOnce('1', 2))->toBe([1, 2]);
});

it('goes on past a failed entry and drops it when the stream skips on failure', function () {
    config()->set('distributable.events.streams.default.on_failure', 'skip');
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect(consumeFailingOnce('1', 2))->toBe([2])
        ->and(Redis::connection()->command('xpending', [config('distributable.events.streams.default.key'), 'analytics'])[0])->toBe(0);
});

it('replays an entry a dead consumer left unacknowledged', function () {
    config()->set('distributable.events.streams.default.claim_after', 0);
    $transport = redisTransport();
    $key = config('distributable.events.streams.default.key');
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));

    Redis::connection()->command('xgroup', ['CREATE', $key, 'analytics', '0', true]);
    Redis::connection()->command('xreadgroup', ['analytics', 'dead-consumer', [$key => '>'], 10]);

    expect(consumeAll($transport, 'analytics', 1))->toBe([1])
        ->and(Redis::connection()->command('xpending', [$key, 'analytics'])[0])->toBe(0);
});

it('trims only the entries every consumer group has acknowledged', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect($transport->trim())->toBe(0);

    consumeAll($transport, 'analytics', 2);

    expect($transport->trim())->toBe(1)
        ->and((int) Redis::connection()->command('xlen', [config('distributable.events.streams.default.key')]))->toBe(1);
});

it('gives back only the entries below what every consumer group has read', function () {
    $transport = redisTransport();
    $ids = fn (): array => collect($transport->entries(null, null, true))->map(fn (Envelope $envelope): int => $envelope->payload['id'])->all();

    foreach ([1, 2, 3] as $id) {
        $transport->publish(Envelope::for(new UserRegistered($id, 'a'), 'iam'));
    }

    consumeAll($transport, 'analytics', 3);
    Redis::connection()->command('xgroup', ['CREATE', config('distributable.events.streams.default.key'), 'iam', '0', true]);

    expect($ids())->toBe([]);

    consumeAll($transport, 'iam', 3);

    expect($ids())->toBe([1, 2]);
});
