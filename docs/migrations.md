# Migrations

Each module has its own database, so `migrate` runs once per module database. It never runs once
for "the application": there is no database that belongs to no module.

## What runs in a module's database

Four sources feed one module's database:

| Source | Where it lives | In the starter | Why it is in every module's database |
|---|---|---|---|
| The application | `database/migrations` | `failed_jobs` and `job_batches` | a failed job or a batch is stored in the database of the module that owns the job class, so each module database needs the table |
| The packages | the paths a package registers | laravel-microservices: `event_consumptions`, unless the guard is off; `event_publications` and its `version` column, only when `DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH` is on | the publication row is written in the emitting module's database, in its transaction; the consumption mark is written in the consuming module's database |
| The module | `{module}/database/migrations` | iam: its users and its permission tables | the module's own tables; no other module may name them |
| The copies | the files or folders the copy's `migrations()` names | `iam_users`, copied into analytics and notifications | the owner publishes the migration, each module that keeps the copy runs it |

Laravel sorts every migration of a run by file name, whatever its source. The table lists where
they come from, not the order they run in.

## The starter, database by database

| Database | Application | Package | Module | Copy | Files |
|---|---|---|---|---|---|
| `iam` | 1 | 0 | 2 | 0 | 3 |
| `analytics` | 1 | 0 | 3 | 1 | 5 |
| `notifications` | 1 | 0 | 1 | 1 | 3 |

The package column counts laravel-microservices only, with the starter's settings: events not
published reliably and the guard off, so no table. `DISTRIBUTABLE_EVENTS_RELIABLE_PUBLISH=true`
adds 2 (`event_publications` and its `version` column), and removing `DISTRIBUTABLE_STREAM_GUARD=false` adds `event_consumptions`. The copy exists in `analytics` and
`notifications` because their providers list `UserShadow` in `$shadows`; iam owns the table and
keeps no copy.

## Running them

```bash
# every module database, one pass each
php artisan migrate

# the databases of these modules only; the option can be repeated
php artisan migrate --module=analytics

# what ran, and what is pending, in each database
php artisan migrate:status
```

`migrate:rollback`, `migrate:reset`, `migrate:refresh` and `migrate:fresh` follow the same rule.
`--seed` seeds each module database with that module's own seeders.

| Rule | Detail |
|---|---|
| The connection | the module's owner connection: the `owner` values laid over the module's connection, or the connection itself when it has no `owner` key (see [Each module's data](./databases#connections)) |
| History | each database keeps its own `migrations` table |
| `--database` or `--path` | the command is Laravel's own, unchanged |
| Tests | `RefreshDatabase` follows the same passes |

## Writing a migration

A migration of the module's own tables goes in the module:

```bash
php artisan make:migration add_archived_at_to_notifications_inbox --module=notifications
```

It lands in `apps/Notifications/database/migrations`.

The migration of a copy is published by the module that owns the table. The copy names where its
migrations are in `migrations()`, next to `owner()` and `sourceTable()`: files or folders, as
Laravel's `loadMigrationsFrom()`. A folder brings every migration in it, whatever its file name.

```php
// foundation/Iam/Shadows/UserShadow.php
class UserShadow extends ShadowModel
{
    public static function owner(): string { return 'iam'; }

    public static function sourceTable(): string { return 'iam_users'; }

    public static function migrations(): array
    {
        return [__DIR__.'/../database/shadows/users'];
    }
}
```

The migration is a plain Laravel migration of the copy's table:

```php
// foundation/Iam/database/shadows/users/2026_01_01_000100_create_iam_users_shadow_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new UserShadow)->getTable(), function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new UserShadow)->getTable());
    }
};
```

The source table is written once, in the copy's `sourceTable()`: renaming it moves neither the
folder nor the migration. A module whose subclass of the copy sets its own `$table` also overrides
`migrations()`, and names a migration of its own `database/migrations`. `modules:doctor` reports
a path a kept copy names that holds no migration.

## Limits

- A module without a database has no migrations. `modules:doctor` reports a module that has some.
- The application's `database/migrations` run in every module database. Keep that folder to what
  every module needs.
- A module cannot name a table that another module's migrations create. `Boundaries` reports it.
  The table of a copy the module keeps is its own.
- The `database` drivers of the cache, the queue and the session have no database to use:
  `modules:doctor` reports them.
