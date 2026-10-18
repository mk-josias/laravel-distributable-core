# Read-only copies (shadows)

A module sometimes needs another module's rows next to its own, to join, filter or sort on them.
Calling the other module once per row would be slow, and reaching into its database is forbidden.
The module keeps a copy instead, in its own database, kept up to date by events. Only the owner
writes the data.

Copies are [laravel-microservices' copies](https://mk-josias.github.io/laravel-microservices/docs/shadows):
each module is a service. This page covers what a module adds: where each piece lives, and which
database the copy is in.

## Copy or call

| | Through the contract | From the copy |
|---|---|---|
| Cost | one call per value, an HTTP call when the owner runs elsewhere | a local query, joins included |
| Freshness | always current | current once the consumer has handled the events |
| Use it for | checking one value, reading one record | lists, filters, sorts |

In the starter, analytics checks one user through iam's contract (`ExistingUser`) and lists
signups with their users from the copy (`SignupResource`).

## How a row travels

```
iam: User saved or deleted
  └─ the row, with the $shadowed fields only, leaves in an event: microservices.shadow.changed
       └─ stream
            ├─ analytics' consumer ─► UserShadow::sync() ─► the copy, in analytics' database
            └─ notifications' consumer ─► UserShadow::sync() ─► the copy, in notifications' database
```

## The three pieces

The owner has the source model and publishes the shape of the copy. Each module that keeps the
copy lists it.

```php
// apps/Iam/app/Models/User.php, the source
final class User extends Model implements Principal, Shadowed
{
    use ShadowSource;

    protected $table = 'iam_users';

    // the fields the other modules' copies carry
    protected array $shadowed = ['name', 'email'];
}
```

Only the `$shadowed` fields, and the dates the row has, leave the module. The `password` of
iam's users, and their tokens, never do.

```php
// foundation/Iam/Shadows/UserShadow.php, the shape of the copy, declared once by iam
class UserShadow extends ShadowModel implements Principal
{
    public static function owner(): string
    {
        return 'iam';
    }

    public static function sourceTable(): string
    {
        return 'iam_users';
    }
}
```

```php
// apps/Analytics/app/Providers/AnalyticsServiceProvider.php, the copies analytics keeps
protected array $shadows = [UserShadow::class];
```

A module lists the foundation's class as it is, or a subclass of its own that adds relations,
casts or a `beforeSync()`. Each listed class is one table: two forms of one source need two
`$table`s, and `modules:doctor` reports two copies in one table.

## Where the copy lives

A copy is a model like the module's others: it reads and writes the database of the module that
is running. The same `UserShadow` is analytics' copy inside analytics and notifications' inside
notifications; outside any module it throws, as every query does. When one consumer serves several
keepers, it enters each in turn before writing its copies.

In the starter, analytics and notifications keep a copy of iam's users. `config/auth.php` sets
`auth.principal` to `UserShadow`: with the default resolver (`LocalPrincipals`), a module loads the
authenticated user of a request from its own copy.

## Reading it, and what it refuses

- A copy rejects any write that doesn't come from `sync()`.
- A source row that is deleted becomes a soft delete in the copy.
- `beforeSync()` derives what the copy needs and the source never announced.
- `shouldBeShadowed()` keeps only some rows: a row it returns `false` for is never written, and
  leaves the copy if it was there.

## Creating its table

iam publishes the migration of the copy's table, extending `Microservices\Migrations\ShadowMigration`,
and `UserShadow::migrations()` names where it is: `foundation/Iam/database/shadows/users`. `migrate`
runs it in the database of every module that keeps a copy. See [Migrations](./migrations#writing-a-migration).

## Filling a copy that is created late

A copy created after the source already had rows is empty. Fill it:

```bash
# on the keeping module: ask the owners to send their rows again
php artisan shadows:want [--keepers=analytics] [--sources=iam_users]
# on the owner: send every row again
php artisan shadows:announce iam_users [--keepers=analytics]
```

Both commands address the event to the keepers, so only those modules update their copy.

## On its own stream

By default a row travels on the default stream, with the other events. A source model can name
another stream in `microservices.events.streams`, and the keeper then reads it with
`stream:consume --stream=shadows`. The keeper must read that stream, or its copy stays empty. See
[laravel-microservices' copies](https://mk-josias.github.io/laravel-microservices/docs/shadows#on-its-own-stream).

`modules:cache` records each module's shadow sources, so a process doesn't scan the module folders
for them at boot.
