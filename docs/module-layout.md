# Where things go in a module

A module is laid out like a Laravel application, and the package reads everything from the folder
and its namespace: there is nothing to register.

## The folders

- `app/`: its code, under `Apps\{Name}\` (`Apps\Iam` for `iam`). Its Artisan commands are every
  `Illuminate\Console\Command` under `app/`, and `app/Providers` holds its providers, listed in the
  module's own `bootstrap/providers.php`.
- `config/*.php`: its settings. `config/database.php` is its database, see [Its database](./databases).
- `routes/{surface}.php`: its routes. `routes/api.php` of `iam` is served under `/iam/api/…`.
- `lang/`: its translations, as `__('iam::messages.hello')`.
- `resources/views/`: its views, as `view('iam::welcome')`. Anonymous components of
  `resources/views/components` and class components of `app/View/Components` are
  `<x-iam::alert />`.
- `database/migrations/`, `database/factories/` (under `Apps\{Name}\Database\Factories\`) and
  `database/seeders/` (under `Apps\{Name}\Database\Seeders\`).
- `tests/`: its tests, under `Apps\{Name}\Tests\`.

What it shares with the other modules is not in its folder, but in the [foundation](./foundation):
its messages and client, its events' payloads, the shape of its copies.

## Where the modules live

`modules.path` (`apps`) and `modules.namespace` (`Apps`) in `config/distributable.php` set where
modules are. A module's name is its folder in snake_case: `apps/PointOfSale` is `point_of_sale`. A
module that lives elsewhere declares its own `path` and `namespace`, see
[One application or several](./applications#what-declared-can-say-about-a-module). `foundation.path`
(`foundation`) is where `modules:doctor` and the architecture test look for the foundation.

## The routes of a module

`routes/{name}.php` is loaded under `distributable.routes.prefix`, `{module}/{name}` by default
(`apps/Iam/routes/api.php` becomes `/iam/api/…`), in the `{name}` middleware group if the application
defines one. A module sets its own prefix with `declared.{module}.prefix`:

```php
'iam' => ['prefix' => 'identity/{name}']   // /identity/api/…
```

A gateway that routes by path must use the same prefixes: the package does not tell it.

## The listeners, handlers and schedule of a module

Its provider, which extends `Distributable\Providers\ServiceProvider`, declares them:

```php
final class IamServiceProvider extends ServiceProvider
{
    // Laravel events the module listens to, as in EventServiceProvider::$listen
    protected array $listen = [
        \Illuminate\Auth\Events\Login::class => [\Apps\Iam\Listeners\RecordLogin::class],
    ];

    // stream events the module handles: event name => Handler classes
    protected array $streamHandlers = [
        'billing.invoice.paid' => [\Apps\Iam\Handlers\UpdatePlan::class],
    ];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('iam:prune')->daily();
    }
}
```

- A listener of `$listen` runs in the module, whichever module dispatched the event. A queued one
  keeps working as Laravel queues it, and an after-commit one runs once the transaction commits,
  still in the module.
- `$streamHandlers` is merged only where the module runs.
- A scheduled task runs in the module, closures included, and only a process that runs the module
  schedules it.

Laravel's own event discovery doesn't see a module's `app/Listeners`: it names
`apps/Iam/app/Listeners/X.php` `Apps\Iam\app\Listeners\X`. Declare the listeners in `$listen`. In a
test, `Event::assertListening()` only sees a closure for them: use
`$this->assertListeningInModule(Login::class, RecordLogin::class)` from `ModuleAware`.

## The settings of a module

The application's `config/` holds the defaults every module shares, and a module's `config/` holds
what is its own. A module's files are merged into the application's file of the same name:

- a list (`['a', 'b']`) gains the items it lacks. A module can't remove or replace an item, so set
  the whole list in the application's config;
- any other array is merged key by key;
- a value the application already sets (`cache.prefix`, a mailer, a disk root) is the module's own
  while its code runs, and the application's comes back outside it. Two modules in one process each
  keep theirs. To build the cache, the mailer or a disk from them, see
  [Its settings and services](./module-context);
- a key the application doesn't set is added for everyone. When two modules of one process set it
  differently, the module registered last wins, and `modules:doctor` reports it.

The merge happens as the module's provider registers, after every package provider: a package that
reads its config in its own `register()` doesn't see the module's values. A module that overrides a
package's config changes it for every module running in that process.

Once `config:cache` has run, the cache holds the merge and the files are not read again.
