# Laravel Distributable Modules

The package lays the foundations for designing each module as a microservice: its code runs in a
context bounded to its module, blind to the others, whether they run in the same process or in
separate, distributed processes, and it talks to them only through contracts and events. Whether
modules run as microservices or several to a process, depending on load, release pace or cost,
becomes an infrastructure choice rather than a code one, allowing greater flexibility.

Laravel lets you declare several database connections, but leaves it to you to name the right one
on every model and query, and nothing stops one part of the application from reaching into
another. The package sets up what is missing:

- **Its own data, with nothing to name.** A module can have its own connection (a database, a
  schema, or a server of its own); its models, transactions, migrations and jobs then use it,
  because the package switches Laravel's default connection as the code moves from one module to
  another.
- **Boundaries that hold.** A module that imports another module's class, or names its tables,
  is reported by `modules:doctor` and fails your test suite, before it is deployed.
- **The same behaviour together or apart.** Events go through a stream and calls through a
  contract in both setups, so moving a module to its own process is a change of `RUN_MODULES`.
- **An image per module.** `modules:purge` removes from an image the code of the modules it
  doesn't run, and `modules:unused-packages` the Composer packages only they required.

```bash
composer require mk-josias/laravel-distributable
# the events driver: Redis Streams (or yours); DISTRIBUTABLE_STREAM_DRIVER=redis
composer require mk-josias/laravel-distributable-stream-redis
# calls to a module running in another process
composer require mk-josias/laravel-distributable-rpc-http
# config/distributable.php, apps/, foundation/FoundationServiceProvider.php
php artisan modules:install
# apps/Iam and foundation/Iam, declared in config/distributable.php
php artisan modules:make iam --database
```

[The starter](https://github.com/mk-josias/laravel-distributable-modules/tree/main/starter) is the example
implementation: an application with three modules, authentication, permissions, Docker images for
one process or one per module, and tests of each module alone. Read it to see the package in use,
or start from it:

```bash
composer create-project mk-josias/laravel-distributable-starter my-app
```

Requires PHP 8.4+ and Laravel 12 or 13. Events, calls and copies between modules come from
[laravel-microservices](https://github.com/mk-josias/laravel-microservices), which an application
on its own can use too: each module is one of its services. There is no other runtime dependency.

Documentation: <https://mk-josias.github.io/laravel-distributable-modules/>

## Why

How many services a platform runs and how many applications its code is split into are two
different questions, usually answered as one.

Running as several services is an infrastructure need: a part has to scale alone, be deployed on
its own schedule, or keep working when another fails. Being several applications, each with its
repository, its pipeline and its dependencies, is only the usual way to get there, and it has a
price of its own: contracts versioned across repositories, the same plumbing rewritten in each,
changes that span several of them.

The need is rarely the same for every part. A few modules have to run apart. Most have no reason
to, and are better off together: one process to deploy and to watch, and calls between them that
never leave it.

This package keeps the two questions apart. The modules are separated the way services are, in
one codebase: each owns its data and talks to the others only through contracts. How many
processes they run in is a setting, chosen module by module and changed in either direction:

```dotenv
# every module in one process
RUN_MODULES=*

# one module alone, the others grouped
# process A
RUN_MODULES=notifications
# process B
RUN_MODULES=iam,analytics
```

A call between two modules of the same process is a direct method call: no HTTP, no signature,
nothing on the network. It becomes a signed HTTP call only between two processes. Events go
through a stream in both setups. The calling code is the same in every case, so grouping modules
or moving one out is not a rewrite.

## How it works

```
                              one codebase
┌──────────────────────────────────────────────────────────────────────┐
│ apps/Iam              apps/Analytics            apps/Notifications   │
│  own data              own data                  own data            │
└──────────────────────────────────────────────────────────────────────┘
          │ RUN_MODULES picks which modules each process boots
          ▼
┌─────────────── process A ───────────────┐   ┌──── process B ────┐
│ iam · analytics                         │   │ notifications     │
└─────────────────────────────────────────┘   └───────────────────┘
       │            ▲         │                        ▲
       │ events     │         └── RPC (signed HTTP) ───┘
       ▼            │
   ┌──────── stream (redis · queue · yours) ────────┐
   └────────────────────────────────────────────────┘
```

Modules communicate in two ways:

| Mode | Used for | Same process | Different processes |
|---|---|---|---|
| Event stream (asynchronous) | announcing that something happened | through the stream | through the stream |
| RPC (synchronous) | asking another module for an answer right away: reading its data, or having it perform an action whose result the caller needs | a direct method call on the module's own class, in its context: no HTTP | a signed HTTP call |

Shadows, the read-only copies described below, are built on events.

When the application boots, the package:

1. reads the modules declared in `config/distributable.php`, and autoloads the ones this process runs
   under `Apps\{Module}\`;
2. registers the service provider of every module this process runs. The provider merges the
   module's config files, loads its routes, translations and commands, and records the
   implementation of each contract the module answers;
3. registers the foundation's service provider, which binds every RPC contract to its
   `RpcService`. The `RpcService` reaches the module in this process when it runs here, over the
   network otherwise.

A module that runs in another process has no config, routes or handlers here. Only its RPC
contracts are bound.

Before any module's code runs, the package makes that module's connection, configuration and
services the current ones, and puts the previous ones back afterwards. This is the module
context: [read how it works](docs/module-context.md).

## Where to go next

| You want to | Read |
|---|---|
| add the package to an existing Laravel application | [Adding it to a Laravel application](docs/getting-started.md) |
| start from a working application | [the starter](https://github.com/mk-josias/laravel-distributable-modules/tree/main/starter) |
| understand the rules the package holds modules to | [The charter](docs/charter.md) |
| declare, generate and check modules | [Modules](docs/modules.md) |
| know what runs in a module's database | [Migrations](docs/migrations.md) and [Each module's data](docs/databases.md) |
| make modules talk | [Calls (RPC)](docs/rpc.md), [Events](docs/events.md), [Read-only copies](docs/shadows.md) |
| clean up the stream and the consumption marks | [Operating the event stream](docs/operating-streams.md) |
| deploy modules together or apart | [Deploying modules](docs/deploying.md) |
| use a package that has its own routes or its own store | [Working with other packages](docs/other-packages.md) |
| replace a transport or another default | [Extending](docs/extending.md) |
| talk to the modules from Node or Go | [Services in other languages](docs/other-languages.md) |
| look up a configuration key or a folder | [Configuration](docs/configuration.md) |

## What the package includes

The package only contains what is needed for modules to be deployed separately. How you write
the application itself (response formats, DTOs, repositories, exceptions, authentication) is up to
you.

A feature is in the package when getting it wrong would break one of its guarantees. Module code
running outside its module's context would write to the wrong database, so the module context is
part of the package. A module without a repository breaks nothing, so repositories aren't.

## Out of scope

- Turning modules on or off at runtime. Which modules run is decided at boot.
- A lockfile per module: one process loads one version of a library, so there is one `vendor/` and
  one lockfile. A module can still declare its own dependencies, see
  [The dependencies of a module](docs/modules.md#the-dependencies-of-a-module).
- Orchestration (Kubernetes, proxies). That belongs to your infrastructure.
- Enforcing a code style.

## Testing

```bash
# pint + phpstan (level 6) + pest
composer check
```

## License

MIT. See [LICENSE](LICENSE).
