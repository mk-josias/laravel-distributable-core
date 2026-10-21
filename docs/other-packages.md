# Working with other packages

A third-party package works in a module as it does in any Laravel application. What changes is where
its data goes, because a module's code runs on the module's database.

## Where a package's data goes

A package that uses Laravel's default connection (a media library, an activity log, permissions)
stores its rows in the database of the module that calls it, so each module has its own. Its tables
are there because its migrations run in every module's database: the ones it loads with
`loadMigrationsFrom()`, and the ones you publish with `vendor:publish` into `database/migrations`.
Move a published migration to a module's `database/migrations` when only that module uses the
package.

Failed jobs and job batches are stored in the database of the module that owns the job. Horizon,
`file` and DynamoDB are untouched.

Outside a module there is no default connection. A package that queries the default connection there fails
loudly, and so does a `database` cache, queue or session store that names no connection.

## A package that keeps one store for the whole application

It belongs to one module. Laravel Sanctum is the usual case: `PersonalAccessToken::findToken()`
reads the default connection, so a token issued in `iam` is not in `analytics`' database. Install it
in `iam` only, and let the other modules validate tokens through a message iam answers:

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
under one cache key, such as spatie/laravel-permission: keep it in one module. Telescope and Pulse
are the same: give them the connection of one module, in their own config file.

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
module's messages, as any module code does.

## Listening to a package's events

A module can listen to a package's events (`Login`, a media event) and still write to its own
database, when it declares the listener in `$listen` of its provider. A listener registered another
way (`Event::listen()` in `boot()`, discovery, a subscriber) runs with the settings of whoever
dispatched the event.

```php
protected array $listen = [
    \Illuminate\Auth\Events\Login::class => [\Apps\Iam\Listeners\RecordLogin::class],
];
```

## Overriding what a package binds

A module's provider registers after every package provider, so a module can override what a package
binds.

## Running a package's command in a module

Every Artisan command takes `--module`, unless it already has one of its own:

```bash
php artisan some:package-command --module=iam
```

It then runs on that module's database. `db:seed --module=iam` runs the module's seeders; without
`--module`, it is Laravel's command.

## Factories

A model of a module finds its factory in the module. A package or an application that sets its own
factory resolver replaces this one, and can fall back on
`Distributable\Context\ModuleFactories::factoryName()` and `modelName()`.

## A class of a module this process doesn't run

`class_exists()` on a class of a module this process doesn't run throws `ModuleException` instead of
returning `false`. A package that probes classes (discovery, morph maps) must meet only the classes
of the modules this process runs.

## Work started in a module

`defer()`, `Concurrency::run()` with the `process` driver and `Octane::concurrently()` keep the
module they were started in. A package that rebinds one of them loses that, for its own work.
