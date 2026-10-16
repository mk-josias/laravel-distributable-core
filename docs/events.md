# Events

Events between modules are [laravel-microservices' events](https://mk-josias.github.io/laravel-microservices/docs/events):
each module is a service, named after it. The envelope, versions, streams, transports, the outbox
and the consumption guard are described there. This page covers what a module adds.

Events go through the stream even when both modules run in the same process, so they behave the
same in every setup.

| | In a module |
|---|---|
| the emitter | the module the event class belongs to: `Apps\Iam\Events\UserRegistered` is emitted by `iam` |
| the handlers | declared in `$handlers` of the module's service provider, merged only where the module runs |
| a handler runs | in its module's [context](../README.md#the-module-context): its queries land in its module's database |
| the outbox | `event_publications` in the emitting module's database, inside the module's transaction |
| the guard | `event_consumptions` in the consuming module's database |
| versions | the payload class sits in the emitter's foundation, declared in `FoundationServiceProvider::$payloads` |
| streams | a module can add its own in its `config/microservices.php` |

```php
// apps/Analytics/app/Providers/AnalyticsServiceProvider.php
protected array $handlers = ['iam.user.registered' => [RecordSignup::class]];

// foundation/FoundationServiceProvider.php
protected array $payloads = ['iam.user.registered' => UserRegisteredPayload::class];
```

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

Run one publisher per module set: two would publish an outbox out of order.

In a test, `Distributable\Testing\ModuleAware::receive('analytics', 'iam.user.registered', [...])`
hands the event to analytics' handlers, in analytics' context, without the stream or iam.
