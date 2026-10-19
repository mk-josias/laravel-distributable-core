# Configuration

## Where the settings live

Every setting is in `config/distributable.php`, which `php artisan vendor:publish
--tag=distributable-config` publishes (`modules:install` does too). A setting that changes with the
environment reads an environment variable, named in its section: set the variable in `.env`, or
the value in the file. A module can add its own streams in its own `config/distributable.php`.
The HTTP transport has a file of its own, see [HTTP RPC](./rpc-http), and the Redis stream its
options in [Redis event stream](./stream-redis). Where a module's files go is in
[Conventions](modules.md#conventions).

## Modules

| Setting | Default | Environment variable | What it does |
|---|---|---|---|
| `declared` | `[]` | | Every module of the application, by name (`apps/Iam` is `iam`). Add an entry to say where a module answers when it runs in another process (`host`), to keep its code elsewhere (`path` and `namespace`), or to change its route `prefix`. See [A module of its own](./modules#a-module-of-its-own). |
| `runs` | `*` | `RUN_MODULES` | The modules this process boots: `*` for all, or a comma-separated list. |
| `modules.path` | `apps` | | The directory where modules live: `apps/Iam` for `iam`. |
| `modules.namespace` | `Apps` | | The root namespace of their classes: `Apps\Iam`. |
| `foundation.path` | `foundation` | | The directory of what modules share. Map it in `composer.json` like your own code; `modules:doctor` and `Boundaries` check it. |
| `routes.prefix` | `{module}/{name}` | | The URL of a module's routes file: `routes/api.php` of `iam` is served under `/iam/api/…`. A gateway that routes by path must match it. |
| `status_route` | `null` | `MODULES_STATUS_ROUTE` | A path that answers which modules this process runs, such as `/`. `null` registers nothing. |

## Calls between modules

### Choose the transport calls travel on

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `rpc.transport` | `null` |
| `.env` | `DISTRIBUTABLE_RPC_TRANSPORT` | not set |

A call to a module that runs in another process goes out on this transport, unless that module's
`host` names another. With `null`, it is `http` once `mk-josias/laravel-distributable-rpc-http` is
installed. The transports are listed in `rpc.transports`, each with a `driver` and its options,
like queue connections; add yours as shown in [Writing a driver](./extending).

### Keep the answers a client received

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `rpc.cache` | `null` |
| `.env` | `DISTRIBUTABLE_RPC_CACHE_STORE` | not set |

The cache store where a client keeps the answers it got. Use one every process can reach, such as
Redis: the module that owns the data forgets an answer there when it writes. `null` is the default
store. See [Keeping an answer](./rpc#keeping-an-answer).

## Events: sending

### Keep an event until it is sent

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `events.reliable_publish` | `false` |
| `.env` | `DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH` | not set |

By default, an event is sent right after the transaction that emitted it commits. If the process
dies at that moment, or the stream is down, the event is lost. Set it to `true` and the event is
also written in the module's database, in the same transaction: it stays there until it is sent,
and `stream:republish` sends the ones that failed.

```dotenv
DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH=true
```

### Choose the stream an event travels on

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `events.stream` | `default` |
| `.env` | `DISTRIBUTABLE_STREAM` | not set |

An event names its stream in its `stream()` method; when it returns `null`, this one is used. The
streams are listed in `events.streams`, each with a driver and its options (the options of `redis`
are in [Redis event stream](./stream-redis)). The `default` stream uses the driver named by
`DISTRIBUTABLE_STREAM_DRIVER`: `array` until you set it to `redis`.

## Events: receiving

### Say which handler runs for which event

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `events.listen` | `[]` |

```php
'events' => ['listen' => ['iam.user.registered' => [SendWelcome::class]]],
```

A module declares its handlers in `$handlers` of its service provider instead. See
[Handle it in another module](./events#handle-it-in-another-module).

### Handle an event only once

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `events.guard` | `null` |
| `.env` | `DISTRIBUTABLE_STREAM_GUARD` | not set |

A stream can deliver the same event twice, after a crash or a retry. The guard notes in the
database that a handler already handled an event, so the second delivery does nothing. Leave it at
`null`: it turns itself on when the stream can deliver twice. `true` or `false` forces it.

### Clean the guard's notes

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `events.guard_cleanup` | `prune` |

The guard's notes pile up in `event_consumptions`. With `prune`, they stay until you run
`stream:prune-consumptions`. With `acknowledge`, a note is deleted as soon as the stream confirms
the event is done, so nothing piles up (Redis only).

## Events: keeping and archiving

### Name the tables

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `events.tables` | `publications` → `event_publications`, `consumptions` → `event_consumptions` |

The two tables written in each module's database: the events waiting to be sent, and the guard's
notes. Change the names only if they clash with yours.

### Choose where an archive goes

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `events.archive` | `file` |
| `.env` | `DISTRIBUTABLE_STREAM_ARCHIVE` | not set |

Where `stream:export` and `stream:import` put what they copy when `--store` is not given. `file`
writes JSON lines; add another store as shown in
[Operating the event stream](./operating-streams#archiving-the-stream).

## Passing context along

| Where | Name | Default |
|---|---|---|
| `config/distributable.php` | `events.propagate` | `[]` |

```php
'events' => ['propagate' => ['trace_id', 'locale']],
```

These keys of Laravel's `Context` are copied into an event's headers when it is emitted, and
restored around each handler. RPC calls carry them too.

## A module's settings and services

Each module runs with its own settings. A module sets them in its own `config/` folder, over the
application's: `apps/Iam/config/database.php` gives `iam` its connection, `apps/Iam/config/cache.php`
its cache. While iam's code runs, `config()` returns iam's values.

### The database

`DB::table('users')` and a model with no connection use the module's own connection: nothing to do.

### Other services

Laravel builds the cache, the mailer, the filesystem and most package clients once, from the
settings it finds the first time, then reuses them. To give each module its own, built from its own
settings, list them in `config/distributable.php`:

```php
'per_module' => [
    'cache', 'cache.store', 'mail.manager', 'mailer', 'filesystem', ReportBuilder::class,
],
```

Each one is listed under the name it is registered with, the one given to `singleton()`:

- the cache: `cache` and `cache.store`;
- the mailer: `mail.manager` and `mailer`;
- the disks: `filesystem`;
- a package's service: the name its provider gives it;
- a singleton of yours that receives one of these in its constructor, such as `ReportBuilder`:
  its class name. Unlisted, it keeps the cache of the module it was first built in.
