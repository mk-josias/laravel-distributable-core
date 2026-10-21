# Adding it to an existing application

`app/` is still your Laravel application. Each module lives in `apps/`. If you would rather start
from a working application, use the
[starter](https://github.com/mk-josias/laravel-distributable-starter).

```
config/distributable.php                   declares the modules: 'declared' => ['iam' => [], …]

apps/Iam/
├── app/                              Apps\Iam\, laid out like a Laravel app
│   ├── Providers/IamServiceProvider.php   the events it listens to, in $streamHandlers
│   ├── Models/User.php
│   └── Events/UserRegistered.php
├── config/
│   └── database.php                  its connections: `modules:make` always writes it
├── database/migrations/
└── routes/api.php                    served under /iam/api/…

foundation/
├── FoundationServiceProvider.php     what the foundation declares for every module
└── Iam/                              Foundation\Iam\, what iam shares with the other modules
    ├── Rpc/FindUser.php              a message iam answers
    ├── Services/IamClient.php        how another process calls iam
    ├── Shadows/UserShadow.php
    └── database/shadows/users/       the copy's migration, named by UserShadow's #[Shadow]
```

The package loads the modules, not the foundation: map `Foundation\` in `composer.json` and list
`Foundation\FoundationServiceProvider` in `bootstrap/providers.php`.

## Declare the modules

```php
// config/distributable.php
'declared' => [
    'iam' => [],
    'analytics' => [],
],
```

```php
// apps/Iam/app/Providers/IamServiceProvider.php
final class IamServiceProvider extends \Distributable\Providers\ServiceProvider {}
```

```php
// apps/Iam/app/Models/User.php: plain Eloquent, it uses iam's database because it runs in iam
final class User extends \Illuminate\Database\Eloquent\Model {}
```

## Give a module its database

```php
// apps/Iam/config/database.php, merged into config/database.php when iam boots
return [
    // the connection the module's code runs on
    'default' => 'iam',
    // creates and alters tables; optional, `default` otherwise
    'owner' => 'iam_owner',
    'connections' => [
        // reads and writes
        'iam' => ['driver' => 'pgsql', 'database' => 'iam', 'username' => 'iam_app'],
        'iam_owner' => ['driver' => 'pgsql', 'database' => 'iam', 'username' => 'iam_owner'],
    ],
];
```

```bash
# migrates each module's database, see Migrations
php artisan migrate
```

## Emit an event from one module and handle it in another

```php
#[\Distributable\Attributes\Emits('iam.user.registered')]
final class UserRegistered extends \Distributable\Events\Event
{
    public function __construct(private readonly int $id) {}

    public function payload(): array
    {
        return ['id' => $this->id];
    }
}
```

```php
app(\Distributable\Contracts\Stream\Bus::class)->emit(new UserRegistered($user->id));
```

```php
// apps/Analytics/app/Handlers/RecordSignup.php
final class RecordSignup implements \Distributable\Contracts\Stream\Handler
{
    public function handle(string $name, array $payload): void
    {
        // record the signup
    }
}
```

```php
// apps/Analytics/app/Providers/AnalyticsServiceProvider.php
protected array $streamHandlers = [
    'iam.user.registered' => [
        RecordSignup::class,
    ],
];
```

```bash
php artisan stream:consume
```

This event is version 1. When the shape of a payload changes, its payload class declares a new
version and how to read the old ones, so events already in the stream stay readable: see
[Change the shape of a payload](./events#change-the-shape-of-a-payload).

## Check that every module could run apart

```bash
php artisan modules:doctor
```

```
Boundary crossed: apps/Analytics/app/Models/Report.php: Apps\Iam\Models\User
[iam] runs elsewhere and serves RPC messages, but distributable.declared.iam.host is not set.
```

It reports a module that imports another module's class or names its tables, an undeclared module,
a missing connection, a missing host or RPC secret, and exits with a non-zero code if it finds
anything. The full list is in [Checking the boundaries](./checking).
