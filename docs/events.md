# Publishing and handling events

An event announces that something happened in a module: iam announces that a user registered,
analytics records a signup, notifications sends a welcome. iam doesn't wait for them and doesn't
know who listens. Events travel on a stream even when the modules run in the same process, so they
behave the same in every setup.

```
iam: emit(UserRegistered) ─► stream ─┬─► analytics: RecordSignup
                                     └─► notifications: SendWelcome
```

## Publish an event

The event's name is shared with the modules that listen, in iam's foundation:

```php
// foundation/Iam/Events/IamEvent.php
enum IamEvent: string
{
    case UserRegistered = 'iam.user.registered';
}
```

The event class lives in iam, and says what it is with `#[Emits]`, as a message does with `#[Rpc]`:

```php
// apps/Iam/app/Events/UserRegistered.php
#[Emits(IamEvent::UserRegistered)]
final class UserRegistered extends Event
{
    public function __construct(private readonly UserRegisteredPayload $user) {}

    public function payload(): array
    {
        return $this->user->toArray();
    }
}
```

`#[Emits]` takes the event's name (the enum case, or a string), and two optional values: `module:`, the module that emits it
(the module the class belongs to when left out; set it for an event class that lives in the
foundation), and `stream:`, the stream it travels on.

```php
// anywhere in iam
app(Bus::class)->emit(new UserRegistered(new UserRegisteredPayload(id: $user->id)));
```

The event leaves once the transaction it was emitted in commits, and never if it rolls back.

## Handle it in another module

The handler lives in the module that reacts:

```php
// apps/Analytics/app/Handlers/RecordSignup.php
final class RecordSignup implements Handler
{
    public function handle(string $name, array $payload): void
    {
        $user = UserRegisteredPayload::from($payload);

        Signup::query()->firstOrCreate(['user_id' => $user->id]);
    }
}
```

and is listed in the module's provider:

```php
// apps/Analytics/app/Providers/AnalyticsServiceProvider.php
protected array $streamHandlers = [
    IamEvent::UserRegistered->value => [
        RecordSignup::class,
    ],
];
```

`Signup::query()` writes to analytics' database: a handler runs with its module's settings.

## Run the consumer

```bash
php artisan stream:consume
```

One consumer serves every module the process runs, each with its own position on the stream: a
module that fails or lags holds back only itself, and a module added later disturbs none of the
others. `--module=analytics` limits it to one module, `--stream=shadows` reads another stream. On
`SIGTERM` it finishes the event it holds, then stops (it needs the `pcntl` extension).

Run one consumer per set of modules and stream, never two: two would break the order a module reads
in.

## Choose the stream

Events travel on the `default` stream. Set its driver in `.env`:

```dotenv
# Redis Streams, from mk-josias/laravel-distributable-stream-redis
DISTRIBUTABLE_STREAM_DRIVER=redis
```

`array` keeps events in memory, for tests; `null` drops them. A stream of its own is an entry in
`distributable.events.streams`, and an event picks it with `#[Emits(..., stream: 'audit')]`. Order is kept only within one
stream. For another broker, see [Writing a driver](./extending).

## Don't lose an event

By default, an event emitted while the process dies, or while the stream is down, between the commit
and the sending, is lost. To keep it:

```dotenv
DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH=true
```

The event is then also written in the module's database, in the same transaction as the data, and
deleted once sent. One that couldn't be sent waits for `php artisan stream:republish`. The starter
leaves it off.

## Handle an event once

A stream can deliver an event twice, after a crash or a retry. When it can, each handler is guarded:
a second delivery of the same event to the same handler does nothing. The guard writes a row in the
consuming module's `event_consumptions` table; the starter turns it off with
`DISTRIBUTABLE_STREAM_GUARD=false`. Cleaning that table and the stream is in
[Operating the event stream](./operating-streams).

## Change the shape of a payload

Adding an optional field keeps the version. Renaming or removing one raises it. The payload class
sits in iam's foundation. `#[Payload]` names its event and its version, and `upcast()` lifts an
older payload one version at a time, so a handler only ever receives the current shape:

```php
// foundation/Iam/Events/UserRegisteredPayload.php
#[Payload(IamEvent::UserRegistered, version: 2)]
final class UserRegisteredPayload extends Dto implements Versioned
{
    public static function upcast(int $from, array $payload): array
    {
        // version 1 called it `name`
        return $from === 1 ? ['id' => $payload['id'], 'full_name' => $payload['name']] : $payload;
    }
}
```

The payload class is listed in `FoundationServiceProvider::$payloads`. A module deployed before the
new version receives an event it can't read: it throws `ModuleException` and runs no handler. With
the `redis` driver blocking on failure, its stream waits until that module is deployed.

## Send an event to some modules only

```php
public function recipients(): array
{
    return ['analytics'];
}
```

Only analytics handles it; the other modules skip it.

## Pass context along

```php
// config/distributable.php
'events' => ['propagate' => ['trace_id', 'locale']],
```

These keys of Laravel's `Context` travel with the event and are restored around each handler. Calls
between modules carry them too.

## Test a handler

```php
$this->receive('analytics', 'iam.user.registered', ['id' => 42]);
```

`receive()`, from `Distributable\Testing\ModuleAware`, hands the event to analytics' handlers, with
no stream and no iam.

## On the wire

Every driver carries the same JSON, which a program in another language can read or write:

```json
{
  "id":         "0191f3c2-8a41-7c2e-9b55-3f1c7d0a4e88",
  "emitter":    "iam",
  "name":       "iam.user.registered",
  "payload":    { "id": 42 },
  "headers":    { "trace_id": "4bf92f35" },
  "emitted_at": "2026-09-30T13:22:41.512000Z",
  "recipients": [],
  "stream":     "default",
  "version":    1
}
```

See [Programs in other languages](./other-languages).
