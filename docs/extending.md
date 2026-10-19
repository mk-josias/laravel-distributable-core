# Writing a driver

Calls and events travel through drivers installed next to the package:
[`http`](./rpc-http) for calls, [`redis`](./stream-redis) for events. This page is for writing your
own, on another broker or another protocol.

## A transport for calls

Register it under a name, and name it on the module it reaches:

```php
// config/distributable.php
'declared' => ['billing' => ['host' => ['url' => 'grpc://billing.internal',
                                   'transport' => 'grpc']]],
'rpc'      => ['transports' => ['grpc' => ['driver' => 'grpc', 'port' => 50051]]],
```

```php
// in a service provider
app(\Distributable\Services\Rpc\TransportManager::class)
    ->extend('grpc', fn ($app, array $config, string $name) => new GrpcTransport($config));
```

The calling side implements `Contracts\Rpc\Transport::invoke(string $module, string $name,
int $version, array $payload)`. The called side hands the same four values to
`MessageRegistry::answer()`, as the HTTP endpoint does.

Two optional interfaces:

- `Contracts\Rpc\ServesRpc`: the transport answers in a process of its own, which `rpc:serve`
  starts;
- `Contracts\Rpc\ReportsRpcProblems`: the transport can tell what stops its calls from working, and
  `modules:doctor` reports it.

## A transport for events

```php
// in a service provider
app(\Distributable\Services\Stream\TransportManager::class)
    ->extend('kafka', fn ($app, array $options, string $stream) => new KafkaTransport($options));
```

A stream in `distributable.events.streams` then uses it with `'driver' => 'kafka'`.

It implements `Contracts\Stream\Transport`:

- `publish()` writes the envelope as `Envelope::toJson()` does, so programs in other languages and
  other drivers read the same thing;
- `consume()` loops until `stop()`, keeps one cursor per consumer, and hands the callback each
  envelope whose emitter is among the channels, with the consumer it was read for. When the callback
  returns, the entry is acknowledged; when it throws, it isn't. A consumer that fails must not hold
  back the others;
- `stop()` lets the current entry finish, then returns from `consume()`: it is what a `SIGTERM`
  calls.

It should keep three guarantees, and say in its README which ones it keeps:

- **order within a stream, including after a failure.** A consumer that reads a copy filled by an
  earlier event (a welcome mail reading the user's copy) depends on it. Without it, an event can
  overtake the one it depends on;
- **at least once**: an unacknowledged entry comes back. Without it, an event can be lost when a
  handler or a process fails;
- **one cursor per consumer**: a new consumer disturbs none of the others. Without it, consumers
  steal each other's entries.

The commands that need more ask for it through an interface of `Distributable\Contracts\Stream`:

- `TrimsStreams`, for `stream:trim`;
- `ArchivesStreams`, for `stream:export`, which reads the entries the stream keeps;
- `ReportsAcknowledgements`, for `guard_cleanup => acknowledge`, which deletes a consumption mark
  once its entry is acknowledged. Without it, the marks stay until `stream:prune-consumptions`;
- `RedeliversEnvelopes`, which turns the consumption guard on. Without it, the guard stays off
  unless events are published reliably;
- `SkipsEntries`, for `stream:skip`.

Without the interface, the command says the transport can't do it.
