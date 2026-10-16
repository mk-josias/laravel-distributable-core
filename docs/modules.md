# Modules

## Declaring a module

A module is a name in `modules.declared`. Its folder is `apps/` followed by the name in
StudlyCase, and everything else is derived from that folder. The configuration only holds what the
folder can't tell, such as the URL of a module that runs elsewhere:

```php
// config/modules.php (php artisan vendor:publish --tag=distributable-config)
'declared' => [
    'iam'       => ['host' => env('IAM_HOST')],
    'analytics' => [],
],
'paths'      => ['modules' => 'apps', 'foundation' => 'foundation'],
'namespaces' => ['modules' => 'Apps', 'foundation' => 'Foundation'],
```

| Key | What it says |
|---|---|
| `declared` | every module of the application, by name. A module's array holds only what its folder can't tell: it can be empty (`'analytics' => []`) |
| `declared.iam.host` | the URL of `iam` when it runs in another process: calls to it go there, as `IAM_HOST=http://iam.internal:8000` does. It can also be `['url' => …, 'transport' => …]` to name another transport. `modules:doctor` reports a module that runs elsewhere and serves a contract without one |
| `declared.{name}.module`, `declared.{name}.foundation` | the folder under `apps/` and under `foundation/` when it isn't the name in StudlyCase: `'iam' => ['module' => 'Identity', 'foundation' => 'Identity']` |
| `paths` | the directories of the modules and of the foundation, and `namespaces` their root namespaces |

`SERVICE_MODULES` is not a key of `declared`: it says which of these modules a process runs (see below).
For example, in a `docker-compose.yml` where `analytics` calls `iam` in another container:

```yaml
  analytics:
    environment:
      SERVICE_MODULES: analytics
      # read by declared.iam.host
      IAM_HOST: "http://iam.svc:8000"
  iam:
    environment:
      SERVICE_MODULES: iam
    networks:
      default:
        # the name analytics calls
        aliases: [iam.svc]
```

| | Module `Iam`, with the defaults |
|---|---|
| name | `iam`, the directory name in snake_case (`^[a-z][a-z0-9_]*$`) |
| namespace | `Apps\Iam`, autoloaded from `apps/Iam/app` by the package: the application needs no `composer.json` entry |
| provider | `Apps\Iam\Providers\IamServiceProvider` |
| database | yes, because `apps/Iam/config/database.php` exists |

```bash
# creates apps/{Module} and foundation/{Module}, and declares it
php artisan modules:make <module> [--database]
# deletes its folders, its declaration and its composer.json entries; its database is left as it is
php artisan modules:delete <module> [--force]
# lists the modules, where they run, their database and host
php artisan modules:list
# deletes the folder of the modules this process doesn't run
php artisan modules:purge
# checks that the modules can run, see "Checking the application"
php artisan modules:doctor
```

`modules:make` also adds the module and the foundation to the `autoload.psr-4` of your
`composer.json`, and the module's tests to `autoload-dev`. The application doesn't read those entries: they are for the tools that read
`composer.json` without booting Laravel, such as your IDE. They follow `paths.modules` and
`namespaces.modules`; if you change these later, `modules:doctor` reports the entries gone stale.
A module outside the project gets none. `modules:purge` can delete a module the entries name:
Composer accepts an entry whose folder is gone.

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
`Distributable\Support\ModuleFactories::factoryName()` and `modelName()`.

## Running a command in a module

`--module` is on every Artisan command, and always names one module:

| Command | What `--module=notifications` does |
|---|---|
| `make:*` | generates in the module (above) |
| `migrate`, `migrate:fresh`… | migrates that module's database only; it can be repeated |
| `db:seed` | runs `Apps\Notifications\Database\Seeders\DatabaseSeeder`; `--class=NotificationSeeder` names a seeder of the module |
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
`app/Models` or `routes/web.php`. You choose the four roots in the configuration, and the folder of a
module under them when it isn't its name; inside a module, the package reads everything from the
folder and the namespace, so there is nothing to register.

| | Configurable | Fixed by convention |
|---|---|---|
| Where the modules live | `paths.modules` (`apps`), `namespaces.modules` (`Apps`); a module's own folder there with `declared.{name}.module` | a module is `{paths.modules}/{Name}`, its name is `{Name}` in snake_case |
| Where the foundation lives | `paths.foundation` (`foundation`), `namespaces.foundation` (`Foundation`); a module's own folder there with `declared.{name}.foundation` | what module `iam` shares is `{paths.foundation}/Iam`, under `Foundation\Iam\` |
| A module's code | | `app/`, under `{namespaces.modules}\{Name}\` |
| A module's providers | | in `app/Providers/`, listed in the module's own `bootstrap/providers.php`: Laravel's layout, read by the package and only where the module runs |
| A module's database | | it has one exactly when a connection named `{name}` exists, declared in the module's `config/database.php` or the root one |
| A module's config, routes, translations, migrations | | `config/*.php`, `routes/{surface}.php` (served under `{name}/{surface}`), `lang/`, `database/migrations/` |
| A module's commands | | every `Illuminate\Console\Command` under `app/` |
| A module's factories and seeders | | `database/factories/` under `{namespaces.modules}\{Name}\Database\Factories\`, `database/seeders/` under `…\Database\Seeders\` |
| A module's views | | `resources/views/`, under the view namespace `{name}::` |
| A module's tests | | `tests/`, under `{namespaces.modules}\{Name}\Tests\` |
| An RPC service | | under the module's foundation folder: `Foundation\Iam\` for `iam`, or `Foundation\Identity\` with `'iam' => ['foundation' => 'Identity']` |
| The migration of a copy | | `{paths.foundation}/{Owner}/database/shadows/` |
| The foundation's provider | | `{namespaces.foundation}\FoundationServiceProvider` |

These conventions are what the features are built on. Because the folder says everything, a module
is found without being registered, `modules:purge` can delete a whole folder, `Boundaries` knows
which module owns a file, and an RPC service knows which module it calls. With a setting for each,
those guarantees would depend on configuration that every process must get right.

## Which modules a process runs

```dotenv
# every module (the default)
SERVICE_MODULES=*
# only these
SERVICE_MODULES=iam,analytics
```

`Distributable\Services\Modules\ModuleRegistry` gives you both lists: `all()` returns every declared
module and `local()` the ones this process runs. `get($name)` throws on an unknown name, and
`forClass($class)` returns the module a class belongs to, based on its namespace.

In production, `php artisan optimize` runs `modules:cache`, which writes what the module folders
tell (namespaces, databases and shadow sources) to `bootstrap/cache/distributable.php`, and the
application reads that file instead of the folders. `optimize:clear` (or `modules:clear`) deletes
it. The cache never decides which modules exist: `modules.declared` does.

## Modules this process doesn't run

The package only autoloads the classes of the modules in `SERVICE_MODULES`, plus the foundation. A
class of any other module is never loaded, even if its file is on disk: using it throws a
`ModuleException`.

```
[Apps\Iam\Services\IamService] belongs to module [iam], which this process does not run (SERVICE_MODULES): go through its foundation contract or an event.
```

This catches, at runtime, a call that `Boundaries` would have caught in your tests. A process still
knows that the other modules exist, because `config/modules.php` declares them: it listens to
their events, and calls them through the RPC services of the foundation.

When you build an image for some modules only, you can delete the folders of the others:

```bash
SERVICE_MODULES=analytics php artisan modules:purge --force
```

Run it in your Dockerfile, after copying the code and before `composer dump-autoload`. Starting an
image with a module in `SERVICE_MODULES` whose folder was purged fails at boot, with the name of the
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
UNUSED="$(SERVICE_MODULES=analytics php artisan modules:unused-packages)"
SERVICE_MODULES=analytics php artisan modules:purge --force
[ -z "$UNUSED" ] || { composer update $UNUSED --no-dev --no-scripts; rm -f bootstrap/cache/packages.php bootstrap/cache/services.php; }
composer dump-autoload --optimize --no-dev
```

A package the root `composer.json` or a module this process runs also requires is never listed.

With `MODULES_STATUS_ROUTE=/`, the process returns the modules it runs:

```json
{ "message": "Hello from laravel-distributable-modules", "modules": ["iam", "analytics"], "status": "ok" }
```

## The module service provider

A provider that extends `Distributable\Providers\ServiceProvider` loads the following from the
module directory:

| Source | What happens |
|---|---|
| `config/*.php` | Merged into the root config file with the same name (see below). Once `config:cache` has run, the cache already holds the merge and the files are not read again. |
| `routes/{name}.php` | Loaded under the `{module}/{name}` prefix, in the `{name}` middleware group if the application defines one. |
| `lang/` | Loaded under the module name: `__('iam::messages.hello')`. |
| `resources/views/` | Loaded under the module name: `view('iam::welcome')`. Anonymous components of `resources/views/components` and class components of `app/View/Components` are `<x-iam::alert />`. |
| Artisan commands | Every `Illuminate\Console\Command` in `app/` is registered (console only). |
| `$listen` on the provider | The Laravel events the module listens to, written as in `EventServiceProvider::$listen`. Each listener runs in the module, whichever module dispatched the event; a queued one keeps working as Laravel queues it, and an after-commit one runs once the transaction commits, still in the module. |
| `$handlers` on the provider | The stream events the module handles, written as `microservices.events.listen`: event name => `Handler` classes. Merged into that config only where the module runs. |
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
| a scalar the root config already sets (`cache.prefix`, a mailer, a disk root) | the module's own value: applied while its code runs, the root one back outside it. Two modules in one process each keep theirs. A service built once and kept (a resolved cache store, a mailer) holds the value it was built with, unless it is listed in `modules.per_module` (`['cache', 'cache.store', 'mail.manager', 'mailer', 'filesystem']`, or a package's service): each module then gets its own instance, built once with its config and swapped in as it runs. An object that received the service in its constructor keeps the instance it was given. |
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
modules are deployed separately. What a module shares with the others (its RPC contracts and
services, the shape of its copies, its event names) goes in `foundation/{Module}/`, autoloaded as
`Foundation\{Module}\` (`modules.paths.foundation`, `modules.namespaces.foundation`). Any module can
use the foundation, and the foundation uses no module.

```
apps/Analytics ──► foundation/Iam ◄── apps/Iam
       └──────── never ──────────────┘
```

The package checks this rule for you, see [Checking the application](#checking-the-application).

### What the foundation holds

It has two kinds of folders:

| Folder | What it is | In the starter |
|---|---|---|
| `foundation/{Module}/` | what that module publishes to the others: its contracts, its `RpcService`, its events and their payloads, the shape of its copies, its permission enums | `Foundation\Iam\Contracts\IamService`, `Foundation\Iam\Services\IamRpcService`, `Foundation\Iam\Events\UserRegisteredPayload`, `Foundation\Iam\Shadows\UserShadow`, `Foundation\Analytics\Enums\AnalyticsPermission` |
| `foundation/Common/` | not a module: it isn't in `modules.declared`. The base classes every module uses, grouped by technical concern (`Auth`, `Http`, `Database`, `Exceptions`, `Validation`…), not by domain | `Foundation\Common\Http\Controller`, `Foundation\Common\Auth\Authenticate` |

`Foundation\Common\Auth\Authenticate` reads from left to right: `Foundation` is the root namespace
of the foundation (`modules.namespaces.foundation`), `Common` is shared by every module and owned
by none, `Auth` is the concern, `Authenticate` is the class. The package doesn't depend on
`Common`: these classes are yours, to change or delete. The starter labels how firmly each piece
is meant, see [`foundation/Common`](https://github.com/mk-josias/laravel-distributable-modules-starter#foundationcommon) in its README.

Where the code of a concern sits follows who needs it: the code that every module uses goes in
`Common`; what only one module's neighbours need to know about it (here, how `iam`'s tokens are
checked: `Foundation\Iam\Auth\JwtTokens`, `RpcTokens`, `GatewayTokens`) goes in that module's folder.

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
| a folder of `apps/` that `modules.declared` doesn't list | `[apps/Notifications] is not declared in modules.declared.` |
| a local module without its `bootstrap/providers.php` | `[iam] …/apps/Iam/bootstrap/providers.php does not exist.` |
| a module whose service provider class doesn't exist | `[notifications] provider Apps\Notifications\Providers\NotificationsServiceProvider does not exist.` |
| a local module with a database but no declared connection | `[iam] connection [iam] is not declared.` |
| a module with migrations but no database | `[iam] has migrations but no database: declare its connection in its config/database.php.` |
| `composer.json` entries that no longer match where the module lives | `composer.json maps [Apps\Iam] to […], but the module lives in […].` |
| two copies kept in one table | `[analytics] keeps two copies in table [users]: … Give each its own $table.` |
| an RPC contract that isn't in the foundation of a declared module | `Foundation\Iam\Contracts\IamService is not in the foundation of a declared module.` |
| `cache.default`, `queue.default` or `session.driver` set to `database` | `[cache.default] is the database driver, but every database belongs to a module: use redis, file or array.` |
| a module running elsewhere that serves a contract, with no host | `[iam] runs elsewhere and serves Foundation\Iam\Contracts\IamService, but modules.declared.iam.host is not set.` |
| RPC contracts declared while the secret is empty | `Modules serve RPC contracts but microservices.rpc.secret is empty: set MICROSERVICES_RPC_SECRET or APP_KEY.` |
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

