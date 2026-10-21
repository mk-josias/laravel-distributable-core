# What a module is

By the end of this page, you know what a module owns, how it reaches the others, what it shares
with them, and where it runs.

## Its own context

While a module's code runs, the package switches the application to that module's context. The
same line of code then reads different things in different modules:

```
DB::table('users')      in iam        → iam's database
DB::table('users')      in analytics  → analytics' database
config('cache.prefix')  in iam        → the value of apps/Iam/config/cache.php
```

A module sets its own values in its `config/` folder; they cover the application's for as long as
that module runs. A service Laravel builds once, such as the cache or the mailer, is listed in
`per_module` to be built for each module.

Details: [Its settings and services](./module-context), [Its database](./databases).

## Reaching the others

```
analytics ── call ────────────────────► iam            "does user 42 exist?"
iam ── event iam.user.registered ─────► analytics, notifications
iam ── events ────────────────────────► a copy of iam's users, in analytics' database
```

- **A call** waits for the answer. Use it to check or read one record.
  [Calling another module](./rpc).
- **An event** announces that something happened. The modules that care handle it, in their own
  time. [Publishing and handling events](./events).
- **A copy** is a table of another module's rows, in your module's database. The package updates
  it from that module's events; you only read it, to filter and join.
  [Keeping a copy of another module's data](./shadows).

A module can't name another module's classes, tables or connections: the way across is one of
these three.

## What they share

Two modules that talk need the same classes: the message one sends and the other answers, the
payload of an event, the shape of a copy. They live in the **foundation**, code that every module
can load. Nothing else is shared, and where you put the foundation is up to you.
[What modules share](./foundation).

## Where it runs

```
RUN_MODULES=*                      one process runs iam, analytics and notifications
RUN_MODULES=iam                    this process runs iam only
declared.iam.host = http://iam     analytics reaches iam there
```

Each process says which modules it runs. A call to a module that runs elsewhere goes over the
network; events travel on a stream in both cases. The code of the modules is the same.
[One application or several](./applications).

## What the package checks

`php artisan modules:doctor` reports a module that leaves its frame: a message that names an
undeclared module, a remote module with no host, a class that names another module's classes.
[Checking the boundaries](./checking), [The charter](./charter).
