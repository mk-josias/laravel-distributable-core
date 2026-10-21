# Checking the boundaries

Two checks report the same boundary violations, for different moments: `modules:doctor` when you
deploy, and `Boundaries` in your tests.

## In your tests

`Distributable\Testing\Boundaries` reads the PHP files of every module, of the foundation and of the
application's `app/`, `routes/` and `config/`. It needs no configuration:

```php
// tests/Architecture/BoundariesTest.php
it('keeps the modules apart', function () {
    expect(app(\Distributable\Testing\Boundaries::class)->violations())->toBe([]);
});
```

`violations()` returns one `{file}: {name}` per reference that crosses a boundary:

- a module may never name another module's namespace;
- a module may never name another module's connection, its owner connection, or a table that
  module's migrations create. The name of a copy the module keeps is its own table, so it is
  allowed;
- the foundation and the application may never name a module's namespace, outside the modules' own
  folders.

It reads qualified class names and plain string literals, so a table or connection name assembled at
runtime isn't seen.

## Before a deployment

```bash
php artisan modules:doctor
```

Run it when you deploy, or in CI with the production environment. It lists every problem and exits
with a non-zero code if there is at least one:

- a folder of `apps/` that `declared` doesn't list:
  `[apps/Notifications] is not declared in distributable.declared.`
- a local module without its `bootstrap/providers.php`, or whose provider class doesn't exist;
- two copies kept in one table: `[analytics] keeps two copies in table [users]: … Give each its own $table.`
- an RPC message that names an undeclared module;
- a module running elsewhere that serves messages, with no host:
  `[iam] runs elsewhere and serves RPC messages, but distributable.declared.iam.host is not set.`
- RPC messages declared while the secret is empty:
  `rpc-http.secret is empty: set DISTRIBUTABLE_RPC_SECRET or APP_KEY.`
- everything `Boundaries` reports:
  `Boundary crossed: apps/Analytics/app/Models/Report.php: Apps\Iam\Models\User`
- two local modules setting one config key to different values:
  `Modules [analytics, iam] set config [iam.flag] to different values: in one process, the module registered last wins.`

## At runtime

A process loads only the classes of the modules in its `RUN_MODULES`. Using a class of another module
throws a `ModuleException`, even if its file is on disk:

```
[Apps\Iam\Repositories\UserRepository] belongs to module [iam], which this process does not run
(RUN_MODULES): go through its foundation messages or an event.
```

This catches at runtime a call that `Boundaries` would have caught in your tests. A process still
knows the other modules exist, because `config/distributable.php` declares them: it listens to their
events and calls them through the clients of the foundation.
