# Modules

## Create a module

```bash
php artisan modules:make billing
```

This creates the module in `apps/Billing` and declares it in `config/distributable.php`:

```
apps/Billing/
├── app/
│   ├── Models/
│   └── Providers/BillingServiceProvider.php
├── bootstrap/providers.php
├── config/database.php
├── database/migrations/
├── routes/api.php
└── tests/Feature/
```

Everything in it is plain Laravel, laid out like an application. Put a model in `app/Models`, a
route in `routes/api.php`, a migration in `database/migrations`: the package finds them from the
folder, so there is nothing to register.

### Its routes

`routes/api.php` is served under `/billing/api/…`. A file named `routes/web.php` would be served
under `/billing/web/…`.

### Its database

`config/database.php` gives the module a connection named `billing`, on its own database. Edit it
to point to yours:

```php
// apps/Billing/config/database.php
return [
    'default' => 'billing',
    'connections' => [
        'billing' => ['driver' => 'pgsql', 'database' => 'billing', 'username' => 'billing_app'],
    ],
];
```

A model in `app/Models` needs no connection: while billing's code runs, Laravel's default
connection is `billing`. `php artisan migrate` creates the tables in each module's database.

### Generating code in it

Laravel's `make:*` commands take `--module`:

```bash
php artisan make:model Invoice -mf --module=billing
```

This writes `apps/Billing/app/Models/Invoice.php`, its migration and its factory. The other
generators are listed in [Generating code in a module](#generating-code-in-a-module).

## Letting your IDE find the classes

The package autoloads the modules itself: the application needs no `composer.json` entry for them,
and the package writes none. The foundation is the application's: map `Foundation\` in
`composer.json` and list its provider in `bootstrap/providers.php`. Your IDE reads `composer.json` without booting
Laravel; to let it resolve the classes, map the foundation in the root file, and each module in its
own, merged by `wikimedia/composer-merge-plugin` (see
[The dependencies of a module](#the-dependencies-of-a-module)):

```json
// composer.json
"autoload": { "psr-4": { "Foundation\\": "foundation/" } },
"extra": { "merge-plugin": { "include": ["apps/*/composer.json"] } }
```

```json
// apps/Billing/composer.json
{
    "autoload": { "psr-4": {
        "Apps\\Billing\\": "app/",
        "Apps\\Billing\\Database\\Factories\\": "database/factories/",
        "Apps\\Billing\\Database\\Seeders\\": "database/seeders/"
    } },
    "autoload-dev": { "psr-4": { "Apps\\Billing\\Tests\\": "tests/" } }
}
```

The paths are relative to the module's folder: they follow it when it moves, and leave with it when
it is deleted.

## Generating code in a module

Laravel's own `make:*` commands take `--module`. They then write in the module, under its namespace,
exactly what they write in `app/` without the option:

```bash
# apps/Notifications/app/Models/Preference.php, its migration and its factory
php artisan make:model Preference -mf --module=notifications
php artisan make:controller PreferenceController --module=notifications
# a new column on the notifications_inbox table of the starter
php artisan make:migration add_archived_at_to_notifications_inbox --module=notifications
```

| Command | Where it writes |
|---|---|
| `make:model`, `controller`, `request`, `resource`, `job`, `event`, `listener`, `policy`, `rule`, `command`, `mail`, `notification`, `enum`, `class`… and the generators other packages add (`make:data`) | `{module}/app/…`, under the module's namespace |
| `make:migration`, and `-m` on `make:model` | `{module}/database/migrations` |
| `make:factory`, `make:seeder` | `{module}/database/factories` and `database/seeders`, under `{Namespace}\Database\Factories` and `\Seeders` |
| `make:config` | `{module}/config` |
| `make:view`, `make:component`, and `--markdown` on `make:mail` and `make:notification` | `{module}/resources/views`; the class that renders the view names it with the module: `view('notifications::components.alert')` |
| `make:test`, and `--test` on the other generators | `{module}/tests/Feature` or `tests/Unit`, under `{Namespace}\Tests` |
| `make:provider` | no `--module`: write it in `{module}/app/Providers` and list it in `{module}/bootstrap/providers.php` |

A module's tests extend your `Tests\TestCase`. PHPUnit runs them once `phpunit.xml` lists them:

```xml
<testsuite name="Modules">
    <directory>apps/*/tests</directory>
</testsuite>
```

Keep in a module the tests that only need that module, and in `tests/` the ones that cross modules:
`Boundaries` reads a module's tests like the rest of its code.

A model's factory is found without any code: `Preference::factory()` resolves
`Apps\Notifications\Database\Factories\PreferenceFactory`, and that factory resolves its model. Laravel's
`#[UseFactory]` on a model still wins, and so does a resolver of your own; yours can fall back on
`Distributable\Context\ModuleFactories::factoryName()` and `modelName()`.

## Running a command in a module

`--module` is on every Artisan command, and always names one module:

| Command | What `--module=notifications` does |
|---|---|
| `make:*` | generates in the module (above) |
| `migrate`, `migrate:fresh`… | migrates that module's database only; it can be repeated |
| `db:seed` | runs `Apps\Notifications\Database\Seeders\DatabaseSeeder`; `--class=NotificationSeeder` names a seeder of the module |
| `model:prune`, `model:show` | finds the models in the module's `app/Models`: `model:show Notification` needs no namespace |
| any other command, yours or a package's | runs in the module's context, on its database |

```bash
# Notification::count() reads notifications' database
php artisan tinker --module=notifications
php artisan model:show 'Apps\Notifications\Models\Notification' --module=notifications
php artisan db:seed --module=notifications
```

Without the option, a command that isn't a module's own runs in no module: `model:show` on a
module's model then throws `ModuleException`, since no database belongs to it.

`migrate --seed` and `migrate:fresh --seed` seed each module database with that module's
`DatabaseSeeder`. A module without one is skipped.

## Conventions

A module is laid out like a Laravel application, by convention, the way Laravel itself expects
`app/Models` or `routes/web.php`. You choose the four roots in the configuration; a module that
lives elsewhere declares its own `path` and `namespace`, and its route `prefix`;
inside a module, the package reads everything from the
folder and the namespace, so there is nothing to register.

| | Configurable | Fixed by convention |
|---|---|---|
| Where the modules live | `modules.path` (`apps`), `modules.namespace` (`Apps`); a module's own folder and namespace with `declared.{name}.path` and `.namespace` | a module is `{modules.path}/{Name}`, its name is `{Name}` in snake_case |
| Where the foundation lives | `foundation.path` (`foundation`): where `modules:doctor` and `Boundaries` look | in the starter, what module `iam` shares is `foundation/Iam`, under `Foundation\Iam\` |
| A module's code | | `app/`, under `{modules.namespace}\{Name}\` |
| A module's providers | | in `app/Providers/`, listed in the module's own `bootstrap/providers.php`: Laravel's layout, read by the package and only where the module runs |
| A module's database | `default` in the module's `config/database.php` | the connection its code runs on; without it, `no_module` |
| A module's config, routes, translations, migrations | | `config/*.php`, `routes/{surface}.php` (served under `{name}/{surface}`), `lang/`, `database/migrations/` |
| A module's commands | | every `Illuminate\Console\Command` under `app/` |
| A module's factories and seeders | | `database/factories/` under `{modules.namespace}\{Name}\Database\Factories\`, `database/seeders/` under `…\Database\Seeders\` |
| A module's views | | `resources/views/`, under the view namespace `{name}::` |
| A module's tests | | `tests/`, under `{modules.namespace}\{Name}\Tests\` |
| An RPC message or client | | in the foundation: `Foundation\Iam\` for `iam` in the starter |
| The migration of a copy | | where the `migrations:` of the copy's `#[Shadow]` point; the starter keeps it in `{foundation.path}/{Owner}/database/shadows/{copy}/` |
| The foundation's provider | | the application's own, listed in its `bootstrap/providers.php` (`Foundation\FoundationServiceProvider` in the starter) |
## A module of its own

A module can declare where it lives instead of following `modules.path` and `modules.namespace`:
its `path` is the folder holding its `app/`, `config/` and `routes/`, its `namespace` the one of its
`app/`. An application of one module declares the project itself:

```php
'declared' => ['iam' => ['path' => '.', 'namespace' => 'App']],
```

Its `config/` and `routes/` are then the application's: the package merges no config and loads no
route, `bootstrap/app.php` does. Two modules cannot be one inside the other, and `modules:delete`
never deletes the project.

## Which modules a process runs

```dotenv
# every module (the default)
RUN_MODULES=*
# only these
RUN_MODULES=iam,analytics
```

`Distributable\Services\ModuleRegistry` gives you both lists: `all()` returns every declared
module and `local()` the ones this process runs. `get($name)` throws on an unknown name, and
`forClass($class)` returns the module a class belongs to, based on its namespace.

In production, `php artisan optimize` runs `modules:cache`, which writes what the module folders
tell (namespaces, databases and shadow sources) to `bootstrap/cache/distributable.php`, and the
application reads that file instead of the folders. `optimize:clear` (or `modules:clear`) deletes
it. The cache never decides which modules exist: `distributable.declared` does.

## Modules this process doesn't run

The package only autoloads the classes of the modules in `RUN_MODULES`. A
class of any other module is never loaded, even if its file is on disk: using it throws a
`ModuleException`.

```
[Apps\Iam\Repositories\UserRepository] belongs to module [iam], which this process does not run
(RUN_MODULES): go through its foundation messages or an event.
```

This catches, at runtime, a call that `Boundaries` would have caught in your tests. A process still
knows that the other modules exist, because `config/distributable.php` declares them: it listens to
their events, and calls them through the RPC clients of the foundation.

When you build an image for some modules only, you can delete the folders of the others:

```bash
RUN_MODULES=analytics php artisan modules:purge --force
```

Run it in your Dockerfile, after copying the code and before `composer dump-autoload`. Starting an
image with a module in `RUN_MODULES` whose folder was purged fails at boot, with the name of the
module.

## The dependencies of a module

The package reads no `composer.json` of its own accord: by default every dependency is in the root
one. A module can declare the libraries only it uses in `{module}/composer.json`, merged into the
root file by [wikimedia/composer-merge-plugin](https://github.com/wikimedia/composer-merge-plugin).
There is still one `composer.lock` and one `vendor/`.

```json
// apps/Iam/composer.json
{ "require": { "spatie/laravel-permission": "^8.3" } }

// composer.json
"extra": { "merge-plugin": { "include": ["apps/*/composer.json"] } }
```

An image built for some modules can then leave out what only the others require.
`modules:unused-packages` prints those packages. Run it before `modules:purge`, which deletes the
files it reads, and name the packages to Composer so that it removes them and changes no other
version:

```bash
# spatie/laravel-permission
UNUSED="$(RUN_MODULES=analytics php artisan modules:unused-packages)"
RUN_MODULES=analytics php artisan modules:purge --force
[ -z "$UNUSED" ] || {
  composer update $UNUSED --no-dev --no-scripts
  rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
}
composer dump-autoload --optimize --no-dev
```

A package the root `composer.json` or a module this process runs also requires is never listed.

With `MODULES_STATUS_ROUTE=/`, the process returns the modules it runs:

```json
{ "message": "Hello from laravel-distributable", "modules": ["iam", "analytics"], "status": "ok" }
```

## The module service provider

A provider that extends `Distributable\Providers\ServiceProvider` loads the following from the
module directory:

| Source | What happens |
|---|---|
| `config/*.php` | Merged into the root config file with the same name (see below). Once `config:cache` has run, the cache already holds the merge and the files are not read again. |
| `routes/{name}.php` | Loaded under `distributable.routes.prefix`, `{module}/{name}` by default (`apps/Iam/routes/api.php` → `/iam/api/…`), in the `{name}` middleware group if the application defines one. A module sets its own with `distributable.declared.{module}.prefix`, e.g. `'iam' => ['prefix' => 'identity/{name}']` → `/identity/api/…`. A gateway that routes by path must use the same prefixes: the package does not tell it. |
| `lang/` | Loaded under the module name: `__('iam::messages.hello')`. |
| `resources/views/` | Loaded under the module name: `view('iam::welcome')`. Anonymous components of `resources/views/components` and class components of `app/View/Components` are `<x-iam::alert />`. |
| Artisan commands | Every `Illuminate\Console\Command` in `app/` is registered (console only). |
| `$listen` on the provider | The Laravel events the module listens to, written as in `EventServiceProvider::$listen`. Each listener runs in the module, whichever module dispatched the event; a queued one keeps working as Laravel queues it, and an after-commit one runs once the transaction commits, still in the module. |
| `$handlers` on the provider | The stream events the module handles, written as `distributable.events.listen`: event name => `Handler` classes. Merged into that config only where the module runs. |
| `schedule(Schedule $schedule)` on the provider | The module's scheduled tasks. Only a process that runs the module schedules them, and `schedule:run` runs each one in the module, closures included. A task scheduled elsewhere runs in no module. |

A module's config files follow the same idea as the module itself: the application's `config/`
holds the defaults every module shares, and a module's `config/` holds what is its own. A value the
application already sets and the module changes is the module's own setting: it applies while the
module's code runs, and the application's value comes back outside it, so two modules in one
process never see each other's settings. A key or an entry the application doesn't have is simply
added for everyone, which is why two modules adding the same key differently is reported by
`modules:doctor`.

How the config merge works, and what it can't do:

| Case | Result |
|---|---|
| a list (`['a', 'b']`) | gains the items it lacks; a module can't remove or replace an item, so set the whole list in the root config |
| any other array, integer keys included (`[404 => …]`) | merged key by key |
| a scalar the root config already sets (`cache.prefix`, a mailer, a disk root) | the module's own value: applied while its code runs, the root one back outside it. Two modules in one process each keep theirs. For the cache, the mailer, the disks and any service built from settings, see [Its settings and services](./module-context). |
| a scalar the root config doesn't set, that two local modules set differently | the module registered last wins in one process, while each keeps its own once they run apart: `modules:doctor` reports it |
| when it runs | as the module's provider registers, after every package provider: a package reading its config in its own `register()` doesn't see the module's values |
| its scope | the whole process: a module overriding a package's config changes it for every module running there |

A module's Laravel listeners go in `$listen`:

```php
final class IamServiceProvider extends ServiceProvider
{
    protected array $listen = [
        \Illuminate\Auth\Events\Login::class => [\Apps\Iam\Listeners\RecordLogin::class],
    ];
}
```

Laravel's own event discovery doesn't see a module's `app/Listeners`: it names
`apps/Iam/app/Listeners/X.php` `Apps\Iam\app\Listeners\X`. Declare the listeners in `$listen`.
In a test, `Event::assertListening()` only sees a closure for them: use
`$this->assertListeningInModule(Login::class, RecordLogin::class)` from `ModuleAware`.

## The foundation

A module never uses another module's classes, because that import would break as soon as the
modules are deployed separately. What a module shares with the others (its RPC messages and
clients, the shape of its copies, its event names) goes in the foundation. The starter keeps it in
`foundation/{Module}/`, autoloaded as `Foundation\{Module}\` by the application's `composer.json`;
the package only reads `distributable.foundation.path` to check it. Any module can use the
foundation, and the foundation uses no module.

```
apps/Analytics ──► foundation/Iam ◄── apps/Iam
       └──────── never ──────────────┘
```

The package checks this rule for you, see [Checking the application](#checking-the-application).

### What the foundation holds

It has two kinds of folders:

| Folder | What it is | In the starter |
|---|---|---|
| `foundation/{Module}/` | what that module publishes to the others: its RPC messages, its clients, its events and their payloads, the shape of its copies, its permission enums | `Foundation\Iam\Rpc\FindUser`, `Foundation\Iam\Services\IamClient`, `Foundation\Iam\Events\UserRegisteredPayload`, `Foundation\Iam\Shadows\UserShadow`, `Foundation\Analytics\Enums\AnalyticsPermission` |
| `foundation/Common/` | not a module: it isn't in `distributable.declared`. The base classes every module uses, grouped by technical concern (`Auth`, `Http`, `Database`, `Exceptions`, `Validation`…), not by domain | `Foundation\Common\Http\Controllers\Controller`, `Foundation\Common\Http\Middleware\Authenticate` |

`Foundation\Common\Http\Middleware\Authenticate` reads from left to right: `Foundation` is the root
namespace of the foundation in the starter, `Common` is shared by every module
and owned by none, `Http\Middleware` is the concern, `Authenticate` is the class. The package doesn't depend on
`Common`: these classes are yours, to change or delete. The starter labels how firmly each piece
is meant, see [`foundation/Common`](https://github.com/mk-josias/laravel-distributable-starter/blob/main/docs/skeleton.md#foundationcommon) in its documentation.

Where the code of a concern sits follows who needs it: the code that every module uses goes in
`Common`; what only one module's neighbours need to know about it (here, how `iam`'s tokens and
the gateway's identity are checked: `Foundation\Iam\Auth\JwtTokens`, `TrustedIdentity`) goes in that module's folder.

## Checking the application

The package has two checks. Both report the same boundary violations, but they are meant for
different moments.

`modules:doctor` checks that the modules can run with the current configuration. Run it when
you deploy, or in CI with the production environment:

```bash
php artisan modules:doctor
```

| It reports | Example |
|---|---|
| a folder of `apps/` that `distributable.declared` doesn't list | `[apps/Notifications] is not declared in distributable.declared.` |
| a local module without its `bootstrap/providers.php` | `[iam] …/apps/Iam/bootstrap/providers.php does not exist.` |
| a module whose service provider class doesn't exist | `[notifications] provider Apps\Notifications\Providers\NotificationsServiceProvider does not exist.` |
| two copies kept in one table | `[analytics] keeps two copies in table [users]: … Give each its own $table.` |
| an RPC message that names an undeclared module | `Foundation\Iam\Rpc\FindUser names module [iam], which is not declared in distributable.declared.` |
| a module running elsewhere that serves messages, with no host | `[iam] runs elsewhere and serves RPC messages, but distributable.declared.iam.host is not set.` |
| RPC messages declared while the secret is empty | `rpc-http.secret is empty: set DISTRIBUTABLE_RPC_SECRET or APP_KEY.` |
| a module using another module's classes, or the foundation or the application (`app/`, `routes/`, `config/`) using a module | `Boundary crossed: apps/Analytics/app/Models/Report.php: Apps\Iam\Models\User` |
| a module naming another module's connection, or a table that module's migrations create | `Boundary crossed: apps/Analytics/app/Models/Report.php: 'iam_users'` |
| two local modules setting one config key to different values | `Modules [analytics, iam] set config [iam.flag] to different values: in one process, the module registered last wins.` |

It lists every problem and exits with a non-zero code if there is at least one.

`Distributable\Testing\Boundaries` is the architecture check on its own, for your test suite. It
doesn't depend on the configuration: it reads the PHP files of every module, of the foundation and
of the application's `app/`, `routes/` and `config/`.

```php
// tests/Architecture/BoundariesTest.php
it('keeps the modules apart', function () {
    expect(app(\Distributable\Testing\Boundaries::class)->violations())->toBe([]);
});
```

`violations()` returns one `{file}: {name}` per reference that crosses a boundary. It applies three
rules:

| Who | May never name |
|---|---|
| a module | another module's namespace |
| a module | another module's connection, its owner connection, or a table that module's migrations create. The name of a copy the module keeps is its own table, so it is allowed |
| the foundation and the application (`app/`, `routes/`, `config/`) | a module's namespace, outside the modules' own folders |

It reads qualified class names and plain string literals, so a table or connection name assembled
at runtime isn't seen.

