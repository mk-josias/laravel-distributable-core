# Read-only copies (shadows)

Copies of another module's rows are [laravel-microservices' copies](https://mk-josias.github.io/laravel-microservices/docs/shadows):
each module is a service. What a module adds is where each piece lives, and which database the
copy is in.

```php
// apps/Iam/app/Models/User.php, the source: the $shadowed fields are copied
final class User extends \Illuminate\Database\Eloquent\Model implements \Microservices\Contracts\Shadows\Shadowed
{
    use \Microservices\Traits\ShadowSource;

    protected $table = 'iam_users';

    protected array $shadowed = ['name'];
}

// foundation/Iam/Shadows/UserShadow.php, the shape of the copy, declared once by iam
class UserShadow extends \Microservices\Models\ShadowModel
{
    public static function owner(): string { return 'iam'; }

    public static function sourceTable(): string { return 'iam_users'; }
}

// apps/Analytics/app/Providers/AnalyticsServiceProvider.php: the copies analytics keeps
protected array $shadows = [\Foundation\Iam\Shadows\UserShadow::class];
```

A module lists the foundation's class as it is, or a subclass of its own that adds relations,
casts or a `beforeSync()`. Each listed class is one table: two forms of one source need two
`$table`s, and `modules:doctor` reports two copies in one table.

A copy is a model like the module's others: it reads and writes the database of the module
running. The same `UserShadow` is analytics' copy inside analytics and notifications' inside
notifications; outside any module it throws, as every query does. When one consumer serves
several keepers, it enters each in turn before writing its copies.

iam publishes the migration of the copy's table in `foundation/Iam/database/shadows/`, extending
`Microservices\Migrations\ShadowMigration`. `migrate` runs it in the database of every module that
keeps a copy.

```bash
# on the keeping module
php artisan shadows:want [--keepers=analytics] [--sources=iam_users]
# on the owner
php artisan shadows:announce iam_users [--keepers=analytics]
```

`modules:cache` records each module's shadow sources, so a process doesn't scan the module
folders for them at boot.
