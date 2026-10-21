# Laravel Distributable

laravel-distributable lets you structure a Laravel application as a set of modules: autonomous
areas, each a business domain (payments, messaging) or a supporting domain the others rely on
(identity, notifications), with a boundary of its own. Modules can run together in one
process, or be distributed as microservices, each in a process of its own, without changing their
code.

Each module runs in an execution context of its own: its own connections (the database among
them), its own configuration and its own services (cache, mailer, disks). While its code runs,
everything it reads comes from that context.

A module reaches the others only through contracts, never through their code: it sends a message
that another module answers, or it publishes an event that the others handle in their own time. When it needs another module's data to
filter or join with its own, it keeps a copy of those rows; the package updates it from the other
module's events, and the module only reads it.

You start with everything in one application. When a module has to scale or ship on its own, you
deploy it in a process of its own, with only its code, and nothing that calls it changes.

## A module, and where it runs

A module is a folder of an ordinary Laravel application, with its own routes, models, migrations
and database:

```bash
php artisan modules:make payments
```

```
apps/Payments/
├── app/
├── config/database.php
├── database/migrations/
├── routes/api.php
└── tests/Feature/
```

Which modules a process runs is a setting, and the code is the same in every case:

```dotenv
# every module in one process
RUN_MODULES=*

# one module alone, the others grouped
# process A
RUN_MODULES=notifications
# process B
RUN_MODULES=iam,analytics
```

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

Modules talk in two ways, and neither depends on where the other runs:

| Way | Used for | Same process | Different processes |
|---|---|---|---|
| Event on the stream | announcing that something happened | through the stream | through the stream |
| Call (RPC) | asking another module for an answer right away | the module's handler is called directly: no HTTP | a call on the module's transport |

A third way, the read-only copy, is built on events: a module keeps the columns it needs from
another module's table, in its own database.

## What the package adds

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

## Install

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

[The starter](https://github.com/mk-josias/laravel-distributable/tree/main/starter) is an application ready
for production, with three modules, authentication through a gateway, permissions, Docker images for
one container or one per module, and tests of each module alone. Start from it:

```bash
composer create-project mk-josias/laravel-distributable-starter my-app
```

Requires PHP 8.4+ and Laravel 12 or 13. There is no other runtime dependency: the events driver and
the calls transport are packages of their own.

Documentation: <https://mk-josias.github.io/laravel-distributable/>

## Why

The package makes the module the unit and the deployment a setting. Each module is built like an
independent service, with its own data and its own contracts, no shared tables and no direct
calls, whether it runs with others in one process or alone in its own application.

Microservices give each part of a system its own data, its own contracts and its own deployment,
and that independence is what lets one part change, scale or move without the others. Existing
module tools organise the code, with routes, providers and migrations per module, but run all of it
in the same context: a module can use a neighbour's connections, configuration or services, and
nothing says no.

The package closes that door. A module's code runs in a context of its own, with only its
connections, configuration and services; `modules:doctor` fails your tests when a module imports a
neighbour's class or names its tables. What is left is the way modules are meant to talk:
messages, events and read-only copies.

What justifies running a module apart is rarely the same for every module. It is an execution need:
the module has to scale alone, be deployed on its own schedule, or keep working when another
fails. Each of these assumes a condition: scaling apart assumes a module whose load is clearly
different, deploying apart a module that changes far more often than the rest. Where the condition
is missing, running apart only adds a server to run, deploy and watch.

Giving a module an application of its own is another decision: it answers needs of teams and
languages, and costs contracts versioned across repositories and the same plumbing written in
each. The package supports it without requiring it. A few modules run apart; most stay together,
in one process, with calls between them that never leave it.

- **Cost follows load.** Group modules into as few processes as the traffic allows; give one its
  own when it earns its own servers.
- **Extraction is a deployment, not a rewrite.** A module already owns its data and speaks only
  through messages and events. Moving it to its own process or application changes a setting; the
  code that calls it, announces to it or copies its rows stays the same.
- **The same behaviour in every topology.** Events always travel on a stream, and a call is the same
  message whether the module answers here or elsewhere.
- **Boundaries you can test.** One repository, one language, one test suite, and `modules:doctor`
  fails the tests when a module crosses a boundary.

When modules share connections and call each other directly, splitting one out is a migration
project. Here it is a setting.

## Where to go next

- **start from a working application:** [the
  starter](https://github.com/mk-josias/laravel-distributable/tree/main/starter)
- **add the package to an existing Laravel application:** [Adding it to an existing
  application](docs/getting-started.md)
- **understand the rules the package holds modules to:** [The charter](docs/charter.md)
- **create a module and give it a database:** [Create a module](docs/modules.md), [Its
  database](docs/databases.md), [Migrations](docs/migrations.md)
- **give a module its own cache, mailer or disks:** [Its settings and
  services](docs/module-context.md)
- **make modules talk:** [Calling another module](docs/rpc.md), [Publishing and handling
  events](docs/events.md), [Copies of another module's data](docs/shadows.md)
- **put modules in one application or in several:** [One application or
  several](docs/applications.md), [Moving a module out](docs/moving-a-module.md)
- **deploy modules together or apart:** [Deploying modules](docs/deploying.md), [Operating the event
  stream](docs/operating-streams.md)
- **talk to the modules from Node or Go:** [Programs in other languages](docs/other-languages.md)
- **use a package that has its own routes or its own store:** [Working with other
  packages](docs/other-packages.md)
- **write a driver for another broker or protocol:** [Writing a driver](docs/extending.md)
- **look up a command or a configuration key:** [Commands](docs/commands.md),
  [Configuration](docs/configuration.md)

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
  [The dependencies of a module](docs/deploying.md#the-libraries-of-a-module).
- Orchestration (Kubernetes, proxies). That belongs to your infrastructure.
- Enforcing a code style.

## Testing

```bash
# pint + phpstan (level 6) + pest
composer check
```

## License

MIT. See [LICENSE](LICENSE).
