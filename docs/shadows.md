# Keeping a copy of another module's data

By the end of this page, `analytics` lists its signups with their users' names in one query, without
calling `iam` for each row.

A module sometimes needs another module's rows next to its own, to join, filter or sort on them.
Calling the other module once per row would be slow, and reaching into its database is forbidden. So
the module keeps a copy in its own database, kept up to date by events. Only the owner writes the
data.

## Copy or call

- **Call** (through a message) to check one value or read one record: it is always current, and costs
  a call on the transport when the owner runs elsewhere.
- **Copy** for lists, filters and sorts: a local query, joins included. It is current once the
  consumer has handled the events.

In the starter, every module asks iam for a user's permissions through its message (`Grants`), and
analytics reads a signup's user from the copy (`Signup::user()` is a `belongsTo` on `UserShadow`).

## Declare the copy

Three pieces, one in each place.

The owner marks the fields that leave its module. Only these, and the dates the row has, are
copied: iam's `password` and tokens never are.

```php
// apps/Iam/app/Models/User.php
#[ShadowFields('name', 'email')]
final class User extends Model implements Principal, Shadowed
{
    use ShadowSource;

    protected $table = 'iam_users';
}
```

The owner publishes the shape of the copy in the foundation, once:

```php
// foundation/Iam/Shadows/UserShadow.php
#[Shadow(module: 'iam', table: 'iam_users', migrations: [__DIR__.'/../database/shadows/users'])]
class UserShadow extends ShadowModel implements Principal
{
}
```

Each module that keeps the copy lists it:

```php
// apps/Analytics/app/Providers/AnalyticsServiceProvider.php
protected array $shadows = [UserShadow::class];
```

That's all. `iam` announces each saved or deleted user, and analytics' consumer writes it into
analytics' own `iam_users` table:

```
iam: User saved or deleted
  └─ the #[ShadowFields] fields leave in an event
       └─ stream
            ├─ analytics' consumer ─► the copy, in analytics' database
            └─ notifications' consumer ─► the copy, in notifications' database
```

`UserShadow` is an ordinary model that reads and writes the database of the module running it: the
same class is analytics' copy inside analytics and notifications' inside notifications.

A module can list a subclass of its own that adds relations, casts or a `beforeSync()`. Each listed
class is one table: two forms of one source need two `$table`s, each with its `migrations:`.
`modules:doctor` reports two copies in one table.

## Create its table

The owner publishes the migration, and `migrations:` in `#[Shadow]` says where it is: files or
folders, as Laravel's `loadMigrationsFrom()`. `php artisan migrate` runs it in the database of every
module that keeps the copy. See [Migrations](./migrations#a-copys-migration).

## Read it

In the starter, analytics and notifications keep a copy of iam's users. `config/auth.php` sets
`auth.principal` to `UserShadow`: with the default resolver, a module loads a request's
authenticated user from its own copy.

A copy refuses what would let it drift from its source:

- it rejects any write that doesn't come from the owner's announcements;
- a source row that is deleted becomes a soft delete in the copy;
- `beforeSync()` derives what the copy needs and the source never announced;
- `shouldBeShadowed()` keeps only some rows. A row it returns `false` for is never written, and
  leaves the copy if it was there:

```php
// analytics keeps only the administrators
protected static function shouldBeShadowed(array $attributes): bool
{
    return (bool) $attributes['is_admin'];
}
```

An announcement that arrives late, after a newer one, is ignored when the source row and the copy
both carry a date: the latest of `updated_at` and `deleted_at`, to the second. A source without those
columns announces no date, and the copy keeps whichever arrived last. Such a copy can be overwritten
by an older announcement; `shadows:announce` repairs it.

## Fill a copy that is created late

A copy created after the source already had rows is empty. Fill it:

```bash
# on the keeping module: ask the owners to send their rows again
php artisan shadows:want [--keepers=analytics] [--sources=iam_users]
# on the owner: send every row again
php artisan shadows:announce iam_users [--keepers=analytics]
```

Both address the event to the keepers, so only those modules update their copy.

## Put a source on its own stream

By default a row travels on the default stream, with the other events. A source model can name
another stream with `#[ShadowStream]`:

```php
// iam: User's rows go on the `shadows` stream, the other events stay on the default one
#[ShadowFields('name', 'email')]
#[ShadowStream('shadows')]
final class User extends Model implements Principal, Shadowed
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

A keeper has to read that stream, or its copy stays empty.

Order is kept only within one stream. A handler that reads a copy an earlier event was meant to fill
(a welcome mail reading the user's copy) needs that copy's source and its own event on the same
stream: on two streams, the event can overtake the copy.

## A program in another language as the source

A program can own the rows: see
[Programs in other languages](./other-languages#being-the-source-of-a-copy).
