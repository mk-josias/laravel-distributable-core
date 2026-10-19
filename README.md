# Laravel Distributable

The package lays the foundations for designing each module as a microservice: its code runs in a
context bounded to its module, blind to the others, whether they run in the same process or in
separate, distributed processes. Each module owns its data, and the modules talk to one another
only through RPC calls on shared messages, events that can be made reliable, and read-only
copies of each other's rows. Whether modules run as microservices or several to a process,
depending on load, release pace or cost, becomes an infrastructure choice rather than a code one:
the code that calls a module, announces to it or copies its rows is the same.

Laravel lets you declare several database connections, but leaves it to you to name the right one
on every model and query, and nothing stops one part of the application from reaching into
another. The package sets up what is missing:

- **Its own data, with nothing to name.** A module can have its own connection (a database, a
  schema, or a server of its own); its models, transactions, migrations and jobs then use it,
  because the package switches Laravel's default connection as the code moves from one module to
  another.
- **Boundaries that hold.** A module that imports another module's class, or names its tables,
  is reported by `modules:doctor` and fails your test suite, before it is deployed.
- **Calls through messages.** A module sends another a message they share. In the same process
  the call is direct; between two processes it goes out on the module's transport (`rpc-http`
  signs it and sends it over HTTP). Moving a module to its own process is a change of
  `RUN_MODULES`.
- **Events that are not lost.** An event is sent once the transaction commits; it can also be kept
  in the database until it is sent. A redelivered event is handled once.
- **Copies of another module's rows.** A module keeps the columns it needs from another module's
  table in its own database, kept up to date by events.
- **Any broker, any language.** Events travel through a driver installed next to the package:
  Redis Streams, or your own through one interface. The wire format is plain JSON, so a program in
  Node or Go can take part.
- **An image per module.** `modules:purge` removes from an image the code of the modules it
  doesn't run, and `modules:unused-packages` the Composer packages only they required.

```bash
composer require mk-josias/laravel-distributable
# the events driver: Redis Streams (or yours); DISTRIBUTABLE_STREAM_DRIVER=redis
composer require mk-josias/laravel-distributable-stream-redis
# calls to a module running in another process
composer require mk-josias/laravel-distributable-rpc-http
# config/distributable.php and apps/
php artisan modules:install
# apps/Iam, declared in config/distributable.php
php artisan modules:make iam
```

[The starter](https://github.com/mk-josias/laravel-distributable/tree/main/starter) is the example
implementation: an application with three modules, authentication, permissions, Docker images for
one process or one per module, and tests of each module alone. Read it to see the package in use,
or start from it:

```bash
composer create-project mk-josias/laravel-distributable-starter my-app
```

Requires PHP 8.4+ and Laravel 12 or 13. There is no other runtime dependency: the events driver and
the calls transport are packages of their own.

Documentation: <https://mk-josias.github.io/laravel-distributable/>

## Why

Microservices give each part of a system its own data, its own contracts and its own deployment.
They also charge for it from day one: one server, one database, one pipeline and one bill per
service, long before the load justifies any of it.

What justifies running apart is rarely the same for every part, and it is not what justifies
separate applications. Running as several services is an execution need: a part has to scale alone,
be deployed on its own schedule, or keep working when another fails. Being several applications,
each with its repository, its pipeline and its dependencies, answers needs of teams and languages,
and it has a price of its own: contracts versioned across repositories, the same plumbing
rewritten in each, changes that span several of them. Each of these benefits also assumes a
condition: scaling apart assumes a part whose load is clearly different, deploying apart a part
that changes far more often than the rest. Where the condition is missing, the cost stays.

The need is rarely the same for every part. A few modules have to run apart. Most have no reason
to, and are better off together: one process to deploy and to watch, and calls between them that
never leave it.

A system is easier to evolve when its parts are separated: each owns its data, no one reads
another's, and they talk only through contracts and events. When something changes in one, you
know where to look and what can break elsewhere. That separation needs neither one application
per domain nor one process per domain. Existing module tools organise the code, with routes,
providers and migrations per module, but run all of it in the same context: a module can use a
neighbour's connection, model or table, and nothing says no. The package closes that door. A
module's code runs in its own context, with only its connections, configuration and services;
outside any module there is no database. And `modules:doctor` fails your tests when a module
imports a neighbour's class or names its tables. What is left is the way the modules are meant to
talk: messages, events and read-only copies.

The package makes the module the unit and the deployment a setting. Each module is built like an
independent service (its own data, no shared tables, no direct calls), and how many processes, or
applications, they run in is chosen module by module and changed in either direction:

```dotenv
# every module in one process
RUN_MODULES=*

# one module alone, the others grouped
# process A
RUN_MODULES=notifications
# process B
RUN_MODULES=iam,analytics
```

- **Cost follows load.** Group modules into as few processes as the traffic allows; give one its
  own when it earns its own servers.
- **Extraction is a deployment, not a rewrite.** A module already owns its data and speaks only
  through messages and events. Moving it to its own process, or its own application, changes a
  setting.
- **The same behaviour in every topology.** Events always travel on a stream, and a call is the
  same message whether the module answers in this process or in another.
- **Microservice discipline, monolith ergonomics.** One repository, one language, one test suite,
  and boundaries that `modules:doctor` checks in your tests.

When modules share a database and call each other directly, splitting one out is a migration
project. Here it is a setting.

A call between two modules of the same process is a direct method call: no HTTP, no signature,
nothing on the network. Only between two processes does it go out on the module's transport
(`rpc-http`: a signed HTTP call). Events go
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
       │ events     │         └── RPC (transport) ────┘
       ▼            │
   ┌──────── stream (redis · queue · yours) ────────┐
   └────────────────────────────────────────────────┘
```

Modules communicate in two ways:

| Mode | Used for | Same process | Different processes |
|---|---|---|---|
| Event stream (asynchronous) | announcing that something happened | through the stream | through the stream |
| RPC (synchronous) | asking another module for an answer right away: reading its data, or having it perform an action whose result the caller needs | the module's handler, called in its context: no HTTP | a call on the module's transport |

Shadows, the read-only copies described below, are built on events.

A process boots only the modules in its `RUN_MODULES`: their routes, commands, handlers and
settings. A module that runs elsewhere is reached through its messages and its events.

Each module's code runs with its own database, settings and services, whichever way it starts: see
[Its settings and services](docs/module-context.md).

## Where to go next

| You want to | Read |
|---|---|
| add the package to an existing Laravel application | [Adding it to a Laravel application](docs/getting-started.md) |
| start from a working application | [the starter](https://github.com/mk-josias/laravel-distributable/tree/main/starter) |
| understand the rules the package holds modules to | [The charter](docs/charter.md) |
| declare, generate and check modules | [Modules](docs/modules.md) |
| look up a command | [Commands](docs/commands.md) |
| know what runs in a module's database | [Migrations](docs/migrations.md) and [Each module's data](docs/databases.md) |
| make modules talk | [Calls (RPC)](docs/rpc.md), [Events](docs/events.md), [Read-only copies](docs/shadows.md) |
| clean up the stream and the consumption marks | [Operating the event stream](docs/operating-streams.md) |
| deploy modules together or apart | [Deploying modules](docs/deploying.md) |
| put modules in one application or in several | [One application or several](docs/applications.md) |
| move a module to an application of its own | [Moving a module](docs/moving-a-module.md) |
| use a package that has its own routes or its own store | [Working with other packages](docs/other-packages.md) |
| replace a transport or another default | [Extending](docs/extending.md) |
| talk to the modules from Node or Go | [Programs in other languages](docs/other-languages.md) |
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
