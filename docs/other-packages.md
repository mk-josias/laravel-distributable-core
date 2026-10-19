# Working with other packages

Third-party packages work as they do in any Laravel application. The package uses a few of
Laravel's own extension points to give each module its database; this table says what each one
does, so you know where a package's data ends up:

| What the package does | Where | What it means for another package |
|---|---|---|
| While a module's code runs, `database.default` is that module's connection | `ModuleContext` | A package that uses the default connection (a media library, an activity log, permissions) stores its rows in the database of the module that calls it, so each module has its own. Its tables are there because its migrations run in every module database. A package that should keep one store for the whole application (Telescope, Pulse) belongs to one module, whose connection it is given in its own config file. |
| `migrate`, `migrate:status`, `migrate:rollback`, `migrate:reset`, `migrate:refresh` and `migrate:fresh` run once per database | `DistributableServiceProvider::registerModuleMigrations()` | A package's migrations, loaded with `loadMigrationsFrom()` or published to `database/migrations`, run in each database with nothing to configure (see [Migrations](./migrations)). With an explicit `--database` or `--path`, the commands behave exactly as Laravel's. A package that replaces these same commands would overlap with this, which is rare; the one registered last is used. |
| Failed jobs and job batches are stored per module, with the `database` drivers | `registerQueueDatabases()` | They go to the database of the module owning the job. Other drivers (Horizon, `file`, DynamoDB) are untouched. |
| Outside a module, the default connection is `no_module`, which throws on every query | `register()`, `ModuleContext` | Every database belongs to a module. A package that queries the default connection outside a module fails loudly, and so does a `database` cache, queue or session store that names no connection. |
| `DeferredCallbackCollection`, the Concurrency `process` driver, Octane's `DispatchesTasks` | `carryTheModuleIntoDeferredWork()` | Work started in a module keeps its module. A package rebinding one of them removes that, for its own work. |
| Module providers register on `booting`, after every package provider | `registerLocalModuleProviders()` | A module can override what a package binds. |
| `Factory::guessFactoryNamesUsing()` and `guessModelNamesUsing()` | `Support\ModuleFactories::register()` | A module model finds its factory in the module; any other class keeps Laravel's rule. A package or an application that sets its own resolver replaces this one, and can delegate to `ModuleFactories`. |
| Every Artisan command gets a `--module` option, unless it already has one | `Console\ModuleOption` | A package's command can be run in a module's context. During a `make:*` command with `--module`, the application path, database path, config path and namespace are the module's, then restored (`Console\ModuleGenerators`). Without `--module`, nothing changes. |
| `db:seed` is replaced | `Console\ModuleSeedCommand` | In a module's context it runs the module's seeders; outside one it is Laravel's command, unchanged. |
| A module's `$listen` wraps each listener in the module's context | `ServiceProvider::registerModuleListeners()` | A module can listen to a package's events (`Login`, a media event) and still write to its own database. A listener registered another way (`Event::listen()` in `boot()`, discovery, a subscriber) runs in the context of whoever dispatched the event. |
| A class of a module this process doesn't run throws `ModuleException` from the autoloader | `autoloadModules()` | `class_exists()` on such a class throws instead of returning `false`. A package probing classes (discovery, morph maps) must only meet classes of the modules this process runs. |

Publishing a package's migrations with `vendor:publish` puts them in `database/migrations`: they
then run in every module database, like your own root migrations. Move a published migration to a
module's `database/migrations` when only that module uses the package.

## A package that keeps one store for the whole application

It belongs to one module. Laravel Sanctum is the usual case: `PersonalAccessToken::findToken()`
reads the default connection, so a token issued in `iam` is not in `analytics`' database, and its
`tokenable` is iam's own model, a class the other processes don't load. Install it in `iam` only,
and let the other modules validate tokens through a message iam answers:

```php
// apps/Iam/app/Rpc/FindUserByTokenHandler.php: the User model uses HasApiTokens
public function handle(FindUserByToken $message): ?array
{
    $user = $this->users->byToken($message->token);

    return $user === null ? null : ['id' => (int) $user->getKey(), 'name' => (string) $user->name];
}
```

Move Sanctum's published migration to `apps/Iam/database/migrations`, so `personal_access_tokens`
exists in iam's database only. The same holds for a package that loads its data once per process
under one cache key, such as spatie/laravel-permission: keep it in one module.

## Packages that serve their own routes (admin panels, Livewire)

A request runs in the module of its route's controller. Filament, Nova or Livewire serve routes
whose controllers belong to no module, so their requests run outside every module and any query
they make fails. Give their routes a module by adding the module's middleware to them, for a
Filament panel:

```php
->middleware([
    // the panel's own middleware
    \Distributable\Http\Middleware\SetModuleContext::class.':iam',
])
```

An admin panel that manages every module's data would cross every boundary. Give each module its
own panel instead (`/admin/iam` in iam, `/admin/analytics` in analytics), each one reading its own
database only. A screen that needs another module's data reads it from a local copy or through that
module's contract, as any module code does.
