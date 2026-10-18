<?php

declare(strict_types=1);

namespace Distributable\Testing;

use Distributable\Contracts\Colocation;
use Distributable\Data\Envelope;
use Distributable\Services\Stream\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/** For a test case: hand a service an event without its emitter running. */
trait InteractsWithServices
{
    /**
     * The emitter is read from the name's first segment; the consumer is the running service unless named.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function receive(string $name, array $payload = [], int $version = 1, ?string $service = null): void
    {
        $envelope = new Envelope(
            id: (string) Str::uuid7(),
            emitter: Str::before($name, '.'),
            name: $name,
            payload: $payload,
            headers: [],
            emittedAt: Date::now()->toImmutable(),
            version: $version,
        );

        $this->app->make(Dispatcher::class)->dispatch($envelope, $service ?? $this->app->make(Colocation::class)->current());
    }
}
