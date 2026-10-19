# Read-only copies (shadows)

A module sometimes needs another module's rows next to its own, to join, filter or sort on them.
Calling the other module once per row would be slow, and reaching into its database is forbidden.
The module keeps a copy instead, in its own database, kept up to date by events. Only the owner
writes the data.

## Copy or call

| | Through the contract | From the copy |
|---|---|---|
| Cost | one call per value, a call on the transport when the owner runs elsewhere | a local query, joins included |
| Freshness | always current | current once the consumer has handled the events |
| Use it for | checking one value, reading one record | lists, filters, sorts |

In the starter, analytics checks one user through iam's contract (`ExistingUser`) and lists
signups with their users from the copy (`SignupResource`).

## How a row travels

```
iam: User saved or deleted
  └─ the row, with the #[ShadowFields] fields only, leaves in an event: distributable.shadow.changed
       └─ stream
            ├─ analytics' consumer ─► UserShadow::sync() ─► the copy, in analytics' database
            └─ notifications' consumer ─► UserShadow::sync() ─► the copy, in notifications' database
```

## The three pieces

The owner has the source model and publishes the shape of the copy. Each module that keeps the
copy lists it.

```php
// apps/Iam/app/Models/User.php, the source
// the fields the other modules' copies carry
#[ShadowFields('name', 'email')]
final class User extends Model implements Principal, Shadowed
{
    use ShadowSource;

    protected $table = 'iam_users';
}
```

Only the `#[ShadowFields]` fields, and the dates the row has, leave the module. The `password` of
iam's users, and their tokens, never do.

```php
// foundation/Iam/Shadows/UserShadow.php, the shape of the copy, declared once by iam
#[Shadow(module: 'iam', table: 'iam_users', migrations: [__DIR__.'/../database/shadows/users'])]
class UserShadow extends ShadowModel implements Principal
{
}
```

```php
// apps/Analytics/app/Providers/AnalyticsServiceProvider.php, the copies analytics keeps
protected array $shadows = [UserShadow::class];
```

A module lists the foundation's class as it is, or a subclass of its own that adds relations,
casts or a `beforeSync()`. Each listed class is one table: two forms of one source need two
`$table`s, each with its own `migrations:`, and `modules:doctor` reports two copies in one table.

## Where the copy lives

A copy is a model like the module's others: it reads and writes the database of the module that
is running. The same `UserShadow` is analytics' copy inside analytics and notifications' inside
notifications; outside any module it throws, as every query does.

In the starter, analytics and notifications keep a copy of iam's users. `config/auth.php` sets
`auth.principal` to `UserShadow`: with the default resolver (`LocalPrincipals`), a module loads the
authenticated user of a request from its own copy.

## Reading it, and what it refuses

- A copy rejects any write that doesn't come from `sync()`.
- A source row that is deleted becomes a soft delete in the copy.
- `beforeSync()` derives what the copy needs and the source never announced.
- `shouldBeShadowed()` keeps only some rows: a row it returns `false` for is never written, and
  leaves the copy if it was there.

```php
// analytics keeps only the administrators
protected static function shouldBeShadowed(array $attributes): bool
{
    return (bool) $attributes['is_admin'];
}
```

An announcement that arrives late, after a newer one, is ignored when the source row and the copy
both carry a date: the latest of `updated_at` and `deleted_at`, to the second. A source without
those columns announces no date, and the copy then keeps whichever announcement arrived last. Such
a copy can be overwritten by an older announcement; `shadows:announce` repairs it.

## Creating its table

iam publishes the migration of the copy's table, and `UserShadow::migrations()` names where it is:
`foundation/Iam/database/shadows/users`. `migrate` runs it in the database of every module that keeps a copy. See [Migrations](./migrations#writing-a-migration).

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
another stream of `distributable.events.streams` by overriding `shadowStream()`; its rows, and only
its rows, travel on it:

```php
// iam: User's rows go on the `shadows` stream, the other events stay on the default one
public function shadowStream(): ?string
{
    return 'shadows';
}
```

```php
// config/distributable.php, in iam and in every module keeping the copy
'events' => ['streams' => [
    'default' => ['driver' => 'redis'],
    'shadows' => ['driver' => 'redis'],
]],
```

```bash
# on the keeper: one consumer per stream
php artisan stream:consume --stream=shadows
```

A keeper has to read that stream, or its copy stays empty. With `events.reliable_publish` on, a
row announced on `shadows` is written to `event_publications` with that stream, then published
there. `shadows:want` and `shadows:announce` need nothing more: the want goes out on the default
stream, the answer on the source's own.

An order is kept only within one stream. A handler that reads a copy an earlier event was meant to
fill (a welcome mail reading the user's copy) needs that copy's source and its own event on the same
stream: on two streams, the event can overtake the copy.

A program in another language can be the source: see
[Other languages](./other-languages#being-the-source-of-a-copy).
