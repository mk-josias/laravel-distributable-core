# Migrations

Each module has its own database, so `migrate` runs once per module database. It never runs once
for "the application": there is no database that belongs to no module.

## What runs in a module's database

Four sources feed one module's database:

| Source | Where it lives | In the starter | Why it is in every module's database |
|---|---|---|---|
| The application | `database/migrations` | `failed_jobs` and `job_batches` | a failed job or a batch is stored in the database of the module that owns the job class, so each module database needs the table |
| The packages | the paths a package registers | laravel-microservices: `event_publications`, `event_consumptions`, and a `version` column | the outbox row is written in the emitting module's database, in its transaction; the consumption mark is written in the consuming module's database |
| The module | `{module}/database/migrations` | iam: its users and its permission tables | the module's own tables; no other module may name them |
| The copies | `foundation/{Owner}/database/shadows/` | `iam_users`, copied into analytics and notifications | the owner publishes the migration, each module that keeps the copy runs it |

Laravel sorts every migration of a run by file name, whatever its source. The table lists where
they come from, not the order they run in.

## The starter, database by database

| Database | Application | Package | Module | Copy | Files |
|---|---|---|---|---|---|
| `iam` | 1 | 3 | 2 | 0 | 6 |
| `analytics` | 1 | 3 | 3 | 1 | 8 |
| `notifications` | 1 | 3 | 1 | 1 | 6 |

The package column counts laravel-microservices only. The copy exists in `analytics` and
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

The migration of a copy is published by the module that owns the table, in
`foundation/{Owner}/database/shadows/`. Its file name matches `*_{source_table}_shadow*.php`, and
it extends `ShadowMigration`:

```php
// foundation/Iam/database/shadows/2026_01_01_000100_create_iam_users_shadow_table.php
return new class extends ShadowMigration
{
    protected function source(): string
    {
        return 'iam_users';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
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
        Schema::dropIfExists($this->table());
    }
};
```

`source()` names the owner's table. `$this->table()` is the table the keeper's copy model reads,
for the module being migrated: two modules can keep the copy under different names, and the same
file creates each one.

## Limits

- A module without a database has no migrations. `modules:doctor` reports a module that has some.
- The application's `database/migrations` run in every module database. Keep that folder to what
  every module needs.
- A module cannot name a table that another module's migrations create. `Boundaries` reports it.
  The table of a copy the module keeps is its own.
- The `database` drivers of the cache, the queue and the session have no database to use:
  `modules:doctor` reports them.
