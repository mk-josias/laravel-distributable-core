# Events

An event announces that something happened in a module. The modules that care handle it later,
each in its own context. The emitter doesn't wait and doesn't know who listens.

Events between modules are [laravel-microservices' events](https://mk-josias.github.io/laravel-microservices/docs/events):
each module is a service, named after it. The envelope, the transports, the outbox and the
consumption guard are described there. This page covers what a module adds.

Events go through the stream even when both modules run in the same process, so they behave the
same in every setup.

## How an event travels

```
iam: Bus::emit(UserRegistered)
  └─ envelope: name iam.user.registered, payload, emitter iam
       ├─ outbox on:  a row in event_publications, in iam's database, in iam's transaction
       │                └─ the publisher sends it to the stream
       └─ outbox off: straight onto the stream
            └─ stream
                 ├─ analytics' consumer ─► RecordSignup, in analytics' context
                 └─ notifications' consumer ─► SendWelcome, in notifications' context
```

## Writing an event

The emitter is the module the event class belongs to: `Apps\Iam\Events\UserRegistered` is emitted
by `iam`. Its name is shared with the modules that listen, as an enum in iam's foundation:

```php
// foundation/Iam/Events/IamEvent.php
enum IamEvent: string
{
    case UserRegistered = 'iam.user.registered';
}
```

```php
// apps/Iam/app/Events/UserRegistered.php
final class UserRegistered extends Event
{
    public function __construct(private readonly UserRegisteredPayload $user) {}

    public function name(): string
    {
        return IamEvent::UserRegistered->value;
    }

    public function payload(): array
    {
        return $this->user->toArray();
    }

    public function version(): int
    {
        return UserRegisteredPayload::version();
    }
}
```

```php
// anywhere in iam
app(Bus::class)->emit(new UserRegistered(new UserRegisteredPayload(id: $user->id)));
```

## Handling an event

A module declares its handlers in `$handlers` of its service provider. They are merged only where
the module runs.

```php
// apps/Analytics/app/Providers/AnalyticsServiceProvider.php
protected array $handlers = [
    IamEvent::UserRegistered->value => [
        RecordSignup::class,
    ],
];
```

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

A handler runs in its module's [context](./module-context): its queries land in that module's
database.

## What a module adds

| | In a module |
|---|---|
| the emitter | the module the event class belongs to |
| the handlers | `$handlers` of the module's service provider |
| the outbox | `event_publications` in the emitting module's database, inside the module's transaction |
| the guard | `event_consumptions` in the consuming module's database |
| versions | the payload class sits in the emitter's foundation, declared in `FoundationServiceProvider::$payloads` |
| streams | a module can add its own in its `config/microservices.php` |

## The outbox and the publisher

With the outbox on, an event is written in the same transaction as the data: both are committed
or both are rolled back, and a publisher sends the row to the stream afterwards. With it off, the
event goes onto the stream when `emit()` is called, whatever happens to the transaction after.

The outbox is a setting of the stream: `MICROSERVICES_STREAM_OUTBOX=true` for the default one. The
publisher is a role of the container, `WITH_PUBLISHER`. Run one per module set: two would publish
an outbox out of order.

The starter leaves the outbox off: `docker-compose.yml` does not set `MICROSERVICES_STREAM_OUTBOX`.
Its events leave as soon as they are emitted.

## Consuming and publishing per module

`stream:consume` reads the envelopes that every declared module publishes, and runs the handlers of
the modules that run in the process. Each module's name is its subscription, with its own cursor on
the stream, so adding a module disturbs none of the others. `stream:consume` has no `--module` of its
own: the option is on every command, and runs it in that module's context. The command then reads the
module it runs in as the only consuming one.

```bash
# every module this process runs, one cursor each
php artisan stream:consume
# every local module's outbox; --module=iam for one
php artisan stream:publish
```

| You run | The consuming modules are |
|---|---|
| `stream:consume --module=analytics` | `analytics`, and the command runs in its context |
| `stream:consume` with no option | every module the process runs (`RUN_MODULES`): one in a process that runs one, all of them in the container that runs several |

What it implies:

| Rule | Why |
|---|---|
| one `stream:consume` per module set is enough | it keeps a cursor per module: the starter's container with `RUN_MODULES=*` and `WITH_CONSUMERS=true` serves `iam`, `analytics` and `notifications` from one process |
| a failing handler delays only its own module | with `on_failure` at `block`, the module that failed retries its entry before reading further, while the others go on |
| never two consumers for the same module and stream | two would break the order it reads in |
| a module's handlers only get the envelopes addressed to it | an envelope with `recipients` set is skipped by the other consumers |
| `--stream=` picks another stream than `microservices.events.stream` | one `stream:consume` per stream, serving every module |
| a `SIGTERM` or `SIGINT` doesn't cut a handler | the consumer finishes the envelope it holds, then stops (it needs the `pcntl` extension) |

## Targeting an event

By default every module can handle an event. An event that overrides `Event::recipients()` with a
list of modules is handled by those modules only: the others acknowledge it and skip it. The
shadow commands use it to address one keeper (see [Read-only copies](./shadows)).

## Handling an event once

A stream can deliver an event twice, after a crash or a retry. When delivery is at-least-once,
each handler run is guarded: an `(event_id, handler)` row is inserted in `event_consumptions`, in
the consuming module's database, in the same transaction as the handler's writes. A second
delivery finds the row and the handler doesn't run.

That table grows, and so do the stream and the outbox. What cleans each one, and when, is in
[Operating the event stream](./operating-streams).

## Changing the shape of a payload

An event keeps its version while its payload only gains optional fields. Renaming or removing a
field raises the version. The payload class implements `Versioned` and sits in the emitter's
foundation (`Foundation\Iam\Events\UserRegisteredPayload`), so every module reads the same shape:
its `upcast()` lifts an older payload one version at a time, before the handler runs.

A consumer older than the event gets a `ServiceException` and no handler runs. On the `redis`
transport with `on_failure` at `block`, the stream then waits for that consumer to be deployed.

## Testing

`Distributable\Testing\ModuleAware::receive('analytics', 'iam.user.registered', [...])` hands the
event to analytics' handlers, in analytics' context, without the stream or iam.
