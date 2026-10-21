# Its database

## Its connection

A module declares its connections in its own `config/database.php`, and `default` names the one its
code runs on:

```php
// apps/Iam/config/database.php
'default' => 'iam',
'owner' => 'iam_owner',
'connections' => [
    // reads and writes rows
    'iam' => [
        'driver' => 'pgsql', 'database' => 'iam',
        'username' => env('IAM_USERNAME'), 'password' => env('IAM_PASSWORD'),
    ],
    // creates and alters tables
    'iam_owner' => [
        'driver' => 'pgsql', 'database' => 'iam',
        'username' => env('IAM_OWNER'), 'password' => env('IAM_OWNER_PASSWORD'),
    ],
],
```

`owner` is optional: the role that owns the tables. Only `migrate` uses it; without it, migrations
run on `default`. The connections can also sit in the root `config/database.php`, next to the
others: the module runs on the one its `default` names.

The connections can point to separate databases on one server, separate schemas of one database,
separate servers, or sqlite files in tests: the code is the same. Several modules can share one
database; give their tables distinct names then (`protected $table = 'iam_users'`).

Without `default`, the module has no connection set: its models must name theirs.

## A second database

A module that needs a warehouse next to its own database declares it, and names it where it uses it:

```php
// apps/Analytics/config/database.php
'default' => 'analytics',
'connections' => [
    'analytics' => ['driver' => 'pgsql', 'database' => 'analytics'],
    'warehouse' => ['driver' => 'pgsql', 'database' => 'warehouse'],
],
```

```php
// apps/Analytics/app/Models/Report.php
protected $connection = 'warehouse';
```

A migration that sets `$connection = 'warehouse'` runs there.

## Models and transactions

Models are plain Eloquent models, `User extends Authenticatable` included. `DB::`,
`DB::transaction()` and `Schema::` use the module's database, with nothing to name:

```php
// apps/Iam/app/Actions/RegisterUser.php
final class RegisterUser
{
    public function execute(string $name): User
    {
        // iam's database
        return DB::transaction(fn () => User::query()->create(['name' => $name]));
    }
}
```

This holds wherever the code starts:

- a request on the module's routes, or on any route whose controller is a module's class;
- a job, a command, an event handler of the module;
- a Laravel listener the module declares in `$listen`, a task it schedules in `schedule()`;
- a call another module sends it, from this process or another;
- a queued closure, `defer()`, `Concurrency::run()` or `Octane::concurrently()` started in the
  module.

A closure sent to another process (Octane tasks, `Concurrency`, queued closures) is rebuilt from its
line in the file: keep one closure per line, or the worker may rebuild the wrong one.

## Code outside a module

Outside a module there is no default connection: a route closure in `routes/web.php`, another package's
route, or Tinker without `--module` throws `ModuleException` ("No module runs here") on its first
query. It goes through the module's client instead, which still works when the module runs
elsewhere:

```php
// never Apps\Iam\Models\User::find($id)
app(\Foundation\Iam\Services\IamClient::class)->findUser($id);
```

There are no foreign keys between modules. Reference another module's rows by id, or keep a
[copy](./shadows) of them.

## In tests

Use `Distributable\Testing\ModuleAware`:

```php
// iam's database
$user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));

// analytics' handlers receive the event, with no stream and no iam
$this->receive('analytics', 'iam.user.registered', ['id' => 42]);
```

`$this->artisan('iam:sync')` runs the command with its module's database, as the real console does.

## Failed jobs and batches

A failed job or a batch is stored in the database of the module its job class belongs to.
`queue:failed`, `queue:retry` and `Bus::findBatch()` read the databases of the modules this process
runs. This needs Laravel's database storage (the `database-uuids` failer and database batches); the
tables come from the application's `database/migrations`, which run in every module's database.
