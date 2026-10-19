# The module context

A module's code stays ordinary Laravel: `User::query()`, `DB::transaction()`, `Cache::get()`, with no
connection or setting named anywhere. What makes that possible is the module context: before a
module's code runs, the package puts in place everything that belongs to that module, and puts the
previous context back afterwards.

![Every way into a module's code goes through ModuleContext::switchTo, which sets the module's config, connection, services and Context; analytics calls iam through its client, the same DB::transaction() lands in iam's database, then analytics' context is restored; outside any module the connection is no_module and any query throws](./images/module-context.png)

## What the context sets

`ModuleContext::switchTo($module)` sets four things, in this order:

| Step | What it does |
|---|---|
| configuration | the application's values come first (`distributable.overlay_base`), then the module's own (`distributable.overlays.{module}`); see [the module's configuration](./modules#the-module-service-provider) |
| connection | `database.default` becomes the module's connection, or `no_module` when the module has no database |
| services | each service listed in `distributable.per_module` is built once for that module, with its configuration, and swapped in while it runs |
| `Context` | the module's name is kept in Laravel's `Context` (hidden key `distributable.module`), which is how it follows a job to its worker |

Outside any module the connection is `no_module`, and any query on it throws a `ModuleException`.
Every database belongs to a module: there is no application database.

## Every way in

The context is set on every entry into a module's code, without that code asking for it: a request
on its routes, a job, a command, an event handler, a listener it declares, a scheduled task, an RPC
call, a queued closure, `defer()`, `Concurrency::run()` and `Octane::concurrently()`. The table of
entry points, with the hook that sets each one and the module it picks, is in
[Models and transactions](./databases#models-and-transactions).

## Entering and leaving

| Call | What it does |
|---|---|
| `enter($module)` | remembers the module that was running and switches to `$module` |
| `leave()` | gives back the module that `enter()` found running: requests and commands that run inside another (a test, Octane, `Artisan::call`) |
| `within($module, $callback)` | switches, runs the callback, and restores the previous context in a `finally` |
| `bind($task)` | returns a closure that runs `$task` in the current module wherever it runs later; it captures only the module's name, so it can be serialized |
| `current()` | the module running now, or `null` outside any module |

The calls nest. When analytics calls `IamClient::findUser()` in the same process, iam's
handler runs in iam's context and analytics' context is restored when the method returns.

## What a module can't do

A module can't put itself in another's context. `within()`, called from another module's code or
from the foundation, throws a `ModuleException`. Only the package, a test, or the module's own code
may enter a module: the way into another module is its messages, see
[the rules of RPC between modules](./rpc#rules-of-rpc-between-modules).
