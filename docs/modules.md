# Create a module

```bash
php artisan modules:make billing
```

This creates the module in `apps/Billing` and declares it in `config/distributable.php`:

```
apps/Billing/
├── app/
│   ├── Models/
│   └── Providers/BillingServiceProvider.php
├── bootstrap/providers.php
├── config/database.php
├── database/migrations/
├── routes/api.php
└── tests/Feature/
```

Everything in it is plain Laravel, laid out like an application. Put a model in `app/Models`, a
route in `routes/api.php`, a migration in `database/migrations`: the package finds them from the
folder, so there is nothing to register. What each folder holds is in
[Where things go in a module](./module-layout).

## Its routes

`routes/api.php` is served under `/billing/api/…`. A file named `routes/web.php` would be served
under `/billing/web/…`.

## Its database

`config/database.php` gives the module a connection named `billing`, on its own database. Edit it
to point to yours:

```php
// apps/Billing/config/database.php
return [
    'default' => 'billing',
    'connections' => [
        'billing' => ['driver' => 'pgsql', 'database' => 'billing', 'username' => 'billing_app'],
    ],
];
```

A model in `app/Models` needs no connection: while billing's code runs, Laravel's default
connection is `billing`. `php artisan migrate` creates the tables in each module's database.

## Generating code in it

Laravel's own `make:*` commands take `--module`. They write in the module, under its namespace,
exactly what they write in `app/` without the option:

```bash
# apps/Billing/app/Models/Invoice.php, its migration and its factory
php artisan make:model Invoice -mf --module=billing
php artisan make:controller InvoiceController --module=billing
# a new column on a table of the module
php artisan make:migration add_paid_at_to_invoices --module=billing
```

- `make:model`, `controller`, `request`, `resource`, `job`, `event`, `listener`, `policy`, `rule`,
  `command`, `mail`, `notification`, `enum`, `class`… and the generators other packages add
  (`make:data`) write in `{module}/app/…`.
- `make:migration`, and `-m` on `make:model`, write in `{module}/database/migrations`.
- `make:factory` and `make:seeder` write in `{module}/database/factories` and `database/seeders`.
- `make:config` writes in `{module}/config`.
- `make:view`, `make:component`, and `--markdown` on `make:mail` and `make:notification`, write in
  `{module}/resources/views`. The class that renders the view names it with the module:
  `view('billing::components.alert')`.
- `make:test`, and `--test` on the other generators, write in `{module}/tests/Feature` or
  `tests/Unit`.
- `make:provider` has no `--module`: write the provider in `{module}/app/Providers` and list it in
  `{module}/bootstrap/providers.php`.

A model's factory is found without any code: `Invoice::factory()` resolves
`Apps\Billing\Database\Factories\InvoiceFactory`, and that factory resolves its model. Laravel's
`#[UseFactory]` on a model still wins, and so does a resolver of your own; yours can fall back on
`Distributable\Context\ModuleFactories::factoryName()` and `modelName()`.

## Testing it

A module's tests extend your `Tests\TestCase`. PHPUnit runs them once `phpunit.xml` lists them:

```xml
<testsuite name="Modules">
    <directory>apps/*/tests</directory>
</testsuite>
```

Keep in a module the tests that only need that module, and in `tests/` the ones that cross modules.

## Running a command in it

`--module` is on every Artisan command, and always names one module:

```bash
# Invoice::count() reads billing's database
php artisan tinker --module=billing
php artisan model:show Invoice --module=billing
php artisan db:seed --module=billing
php artisan migrate --module=billing
```

- `migrate` and its variants migrate that module's database only. The option can be repeated.
- `db:seed` runs `Apps\Billing\Database\Seeders\DatabaseSeeder`; `--class=InvoiceSeeder` names
  another seeder of the module.
- `model:prune` and `model:show` find the models in the module's `app/Models`, so `model:show
  Invoice` needs no namespace.
- Any other command, yours or a package's, runs on the module's database.

Without the option, a command that isn't a module's own runs in no module: `model:show` on a
module's model then throws `ModuleException`, since no connection is set for it.
`migrate --seed` and `migrate:fresh --seed` seed each module's database with that module's
`DatabaseSeeder`; a module without one is skipped.

## Letting your IDE find the classes

The package autoloads the modules itself: the application needs no `composer.json` entry for them.
Your IDE reads `composer.json` without booting Laravel. To let it resolve the classes, map the
foundation in the root file, and each module in its own `composer.json`, merged by
`wikimedia/composer-merge-plugin`:

```json
// composer.json
"autoload": { "psr-4": { "Foundation\\": "foundation/" } },
"extra": { "merge-plugin": { "include": ["apps/*/composer.json"] } }
```

```json
// apps/Billing/composer.json
{
    "autoload": { "psr-4": {
        "Apps\\Billing\\": "app/",
        "Apps\\Billing\\Database\\Factories\\": "database/factories/",
        "Apps\\Billing\\Database\\Seeders\\": "database/seeders/"
    } },
    "autoload-dev": { "psr-4": { "Apps\\Billing\\Tests\\": "tests/" } }
}
```

The paths are relative to the module's folder: they follow it when it moves, and leave with it when
it is deleted.

## Removing it

```bash
# deletes its folders and its declaration; its database is left as it is
php artisan modules:delete billing
```
