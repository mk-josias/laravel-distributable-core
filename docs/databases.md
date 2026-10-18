# Each module's data

## Connections

A module declares its connections in its `config/database.php`, as the root one does for the
application: `default` names the one it runs on.

```php
// apps/Analytics/config/database.php
'default' => 'main',
'connections' => ['main' => [...], 'warehouse' => [...]],
```

Without `default`, the module runs on the connection named after it: module `iam` on `iam`. A
connection name belongs to one module: two modules declaring the same one throw `ModuleException`
at boot, rather than one overwriting the other.

```php
// apps/Iam/config/database.php
'owner' => 'iam_owner',
'connections' => [
    // reads and writes rows
    'iam' => ['driver' => 'pgsql', 'database' => 'iam', 'username' => env('IAM_USERNAME'), 'password' => env('IAM_PASSWORD')],
    // creates and alters tables
    'iam_owner' => ['driver' => 'pgsql', 'database' => 'iam', 'username' => env('IAM_OWNER'), 'password' => env('IAM_OWNER_PASSWORD')],
],
```

`owner` names, as `default` does, a connection the file declares: the role that owns the tables
and can create and alter them. Only the `migrate` commands and the module's DDL use it. Without
it, they run on `default`, which suits one role for everything. The root `config/database.php`
sets `'owner' => null`, as it sets `default`, so that each module's value is its own.

Declare the connection where you prefer; the module has a database as soon as it exists:

| Where | Result |
|---|---|
| the module's `config/database.php` | merged into `database.connections`; the module carries its own setup |
| the root `config/database.php`, next to `sqlite` and `pgsql` | the same, with every connection in one file |
| nowhere | the module has no database: its queries fail with `no_module`, and `modules:doctor` reports any migration it has |

A module may need a second database, a warehouse next to its own for instance. It declares that
connection in its `config/database.php`, and its code names it, on a model or a query:

```php
// apps/Analytics/config/database.php
'connections' => [
    'analytics' => ['driver' => 'pgsql', 'database' => 'analytics'],
    'warehouse' => ['driver' => 'pgsql', 'database' => 'warehouse'],
],
```

```php
// apps/Analytics/app/Models/Report.php
protected $connection = 'warehouse';
```

A module's code uses only the connections its module declares: its own, the one `owner` names,
and each connection of its `config/database.php`. Naming another one, another module's
or one of the root `config/database.php` only, throws `ModuleException`. A migration that sets
`$connection = 'warehouse'` runs on that connection; the others run on the owner connection. Laravel's
and the packages' code (queues, sessions, failed jobs, events) opens the connections it needs and is
not checked.

The connections can point to separate databases on one server, separate schemas of one database,
separate servers, or sqlite files in tests. The code is the same in every case. Several modules can
also share one database; the stored events filter their rows by emitting module.

There are no foreign keys between modules. Reference another module's rows by id, or keep a
[shadow](shadows.md) of them.

## Models and transactions

Models are plain Eloquent models, including `User extends Authenticatable`. Requests to a module's
routes, and jobs, commands and event handlers whose class belongs to a module, run in that module's
context: its connection becomes the default one. Models, `DB::`, `DB::transaction()` and `Schema::`
then use the module's database without any extra code. If several modules share a database, give
their tables distinct names (`protected $table = 'iam_users'`).

```php
// apps/Iam/app/Actions/RegisterUser.php, called from an iam route, job or command
final class RegisterUser
{
    public function execute(string $name): User
    {
        // iam database
        return DB::transaction(fn () => User::query()->create(['name' => $name]));
    }
}
```

| Entry point | Hook | Module |
|---|---|---|
| request on `/{module}/…` | `Http\Middleware\SetModuleContext` on the module's routes | the route's module |
| any route whose controller is a module's class, wherever the route is declared | `RouteMatched` | the module of the controller class |
| job | `Queue::before` | the module of the job class |
| command | `CommandStarting` | the module of the command class |
| event handler | `Dispatcher`, around each handler | the module of the handler class |
| Laravel event listener declared in a module's `$listen` | `ServiceProvider`, around each listener | the module that declares it |
| scheduled task declared in a module's `schedule()` | `ScheduledTaskStarting` | the module that declares it |
| RPC call, from this process or another | `LocalServices`, around the contract's method | the module that implements the contract |
| queued closure, or any job class outside a module | `Queue::before`, from the Context Laravel carries into the job | the module that queued it |
| `defer(fn () => …)` | a `DeferredCallbackCollection` that wraps each callback | the module that deferred it |
| `Concurrency::run()` (`process` driver) | a wrapper around the driver: the child process gets only the closure | the module that started it |
| `Octane::concurrently()` | a `DispatchesTasks` bound by the package, for the task workers | the module that started it |

The last line is why a module never switches databases itself: when analytics calls
`IamService::findUser()` and iam runs in the same process, iam's implementation still runs in iam's
context and reads iam's database. The context is restored once the method returns.

For your own deferred work, `ModuleContext::bind($task)` returns a closure that runs `$task` in the
current module wherever it runs later. It captures only the module's name, so it can be serialized.
A closure sent to another process (Octane tasks, `Concurrency`, queued closures) is rebuilt by
`laravel/serializable-closure` from its line in the file: keep one closure per line, or the worker
may rebuild the wrong one.

Every database belongs to a module: there is no application database. Outside a module, the
default connection is `ModuleContext::NO_MODULE`, and any query on it throws `ModuleException`
("No module runs here"). Code that isn't in a module (a route closure in `routes/web.php`, another
package's route, Tinker without `--module`) therefore never uses a module's models. It goes
through the module's contract, which runs in the module's context and still works when the module
runs elsewhere:

```php
// never Apps\Iam\Models\User::find($id)
app(\Foundation\Iam\Contracts\IamService::class)->findUser($id);
```

`Context\ModuleContext::current()` returns the current module (`Data\Module`) or null,
and `within($module, $callback)` runs a callback in a module's context. Only the package, tests and
the module's own code may enter a module: when the code calling `within()` (directly, or through
`Colocation::within()`) is another module's or the foundation's, it throws `ModuleException`,
whatever names the module, a string, a variable or an enum. The check reads the caller's file
from the call stack, so code that never runs is not checked, and `modules:doctor` cannot see it.

In tests, use `Distributable\Testing\ModuleAware`:

```php
// iam database
$user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));
```

The trait also turns on Laravel's console events in tests, so `$this->artisan('iam:sync')` runs
the command in its module's context, as the real console does.

To test a module without the module that emits its events, hand it the event directly. Its handlers
run in its context, with no stream and no consumer:

```php
// emitter: iam, read from the name
$this->receive('analytics', 'iam.user.registered', ['id' => 42]);
```

## Migrations

`migrate` runs once per module database, on its owner connection, with the application's, the
packages', the module's and the copies' migrations. See [Migrations](./migrations).

## Queued jobs

Failed jobs and job batches are stored in the database of the module the job class belongs to.
`queue:failed`, `queue:retry` and `Bus::findBatch()` read from the databases of the modules this
process runs. This works when Laravel stores them in a database (the `database-uuids` failer and
database batches). The tables come from the application's `database/migrations`, which run in every
module database.

