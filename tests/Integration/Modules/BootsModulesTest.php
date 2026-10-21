<?php

declare(strict_types=1);

use Apps\Iam\Models\User;
use Apps\Iam\Providers\IamServiceProvider;
use Distributable\Config\Modules;
use Distributable\Services\ModuleRegistry;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

uses(ModuleAppTestCase::class);

it('builds the registry from the configured source', function () {
    $registry = $this->app->make(ModuleRegistry::class);

    expect(array_map(fn ($module) => $module->name, $registry->all()))->toBe(['analytics', 'gateway', 'iam'])
        ->and($registry->get('gateway')->hasDatabase)->toBeFalse();
});

it('registers every provider a module lists in its bootstrap/providers.php', function () {
    expect($this->app->make('gateway.health'))->toBe('registered');
});

it('answers which modules this process runs on the status route', function () {
    $this->get('/')->assertOk()->assertExactJson([
        'message' => 'Hello from laravel-distributable',
        'modules' => ['analytics', 'gateway', 'iam'],
        'status' => 'ok',
    ]);
});

it('serves a module route file under the {module}/{name} prefix', function () {
    $this->get('/iam/api/v1/ping')->assertOk()->assertContent('pong');
});

it('takes the route prefix from distributable.routes.prefix, and a module its own from distributable.declared', function () {
    config()->set('distributable.routes.prefix', 'modules/{module}/{name}');
    config()->set('distributable.declared.iam.prefix', 'identity/{name}');
    $config = $this->app->make(Modules::class);

    expect($config->getRoutePrefix('analytics', 'api'))->toBe('modules/analytics/api')
        ->and($config->getRoutePrefix('iam', 'api'))->toBe('identity/api');
});

it('wraps a route file in the middleware group of the same name when one exists', function () {
    $route = $this->app->make(Router::class)->getRoutes()->match(Request::create('/iam/admin/hello'));

    expect($route->gatherMiddleware())->toContain('admin');
});

it('keeps what a module sets, a value, a key, a list or an empty array, for the time it runs', function () {
    expect(config('iam.flag'))->toBeNull()
        ->and(config('iam.flag', 'default'))->toBe('default')
        ->and($this->inModule('iam', fn () => config('iam.flag')))->toBeTrue()
        ->and($this->inModule('analytics', fn () => config('iam.flag', 'default')))->toBe('default')
        ->and(config('iam.only.deep', 'none'))->toBe('none')
        ->and($this->inModule('iam', fn () => config('iam.only.deep')))->toBe('iam')
        ->and(config('iam.only.deep', 'none'))->toBe('none')
        ->and(config('iam.items'))->toBe(['from-root'])
        ->and($this->inModule('iam', fn () => config('iam.items')))->toBe(['from-module'])
        ->and(config('iam.cleared'))->toBe(['root'])
        ->and($this->inModule('iam', fn () => config('iam.cleared')))->toBe([])
        ->and(config('iam.nested'))->toBe(['kept' => 'root', 'override' => 'root'])
        ->and($this->inModule('iam', fn () => config('iam.nested')))->toBe(['kept' => 'root', 'override' => 'module'])
        ->and($this->inModule('analytics', fn () => config('iam.nested.override')))->toBe('root');
});

it('keeps what each context sets while it runs: the application its own, a module its own', function () {
    $this->inModule('iam', fn () => config()->set('iam.nested.override', 'set-in-iam'));
    config()->set('iam.nested.override', 'set-at-root');
    config()->set('iam.nested.kept', 'root-again');

    expect(config('iam.nested'))->toBe(['kept' => 'root-again', 'override' => 'set-at-root'])
        ->and($this->inModule('analytics', fn () => config('iam.nested')))->toBe(['kept' => 'root-again', 'override' => 'set-at-root'])
        ->and($this->inModule('iam', fn () => config('iam.nested')))->toBe(['kept' => 'root-again', 'override' => 'set-in-iam'])
        ->and(config('iam.nested.override'))->toBe('set-at-root');
});

it('keeps for the module what it names in a table the process looks up: neither the application nor another module sees it', function (string $entry) {
    expect(config($entry))->toBeNull()
        ->and($this->inModule('analytics', fn () => config($entry)))->toBeNull()
        ->and($this->inModule('iam', fn () => config($entry)))->not->toBeNull();
})->with([
    'database.connections.iam',
    'queue.connections.iam_queue',
    'cache.stores.iam_store',
    'filesystems.disks.iam_disk',
    'mail.mailers.iam_mailer',
    'logging.channels.iam_channel',
    'broadcasting.connections.iam_broadcaster',
    'auth.guards.iam_guard',
    'auth.providers.iam_provider',
]);

it('adds to the application what a module declares in its config/distributable.php', function () {
    expect(config('distributable.events.streams.audit'))->not->toBeNull()
        ->and($this->inModule('analytics', fn () => config('distributable.events.streams.audit')))->not->toBeNull();
});

it('keeps for the module a value it changes in an entry of such a table the application defines', function () {
    expect(config('cache.stores.array.serialize'))->toBeFalse()
        ->and($this->inModule('iam', fn () => config('cache.stores.array.serialize')))->toBeTrue()
        ->and($this->inModule('analytics', fn () => config('cache.stores.array.serialize')))->toBeFalse();
});

it('merges an array keyed by integers key by key, as it does any other map', function () {
    expect($this->inModule('iam', fn () => config('iam.codes')))->toBe([403 => 'root forbidden', 404 => 'module not found'])
        ->and(config('iam.codes'))->toBe([403 => 'root forbidden', 404 => 'root not found']);
});

it('keeps the list a module sets as it is, however many times its provider registers', function () {
    $this->app->register(IamServiceProvider::class, force: true);

    expect($this->inModule('iam', fn () => config('iam.items')))->toBe(['from-module']);
});

it('keeps the cached config as it is, instead of merging the module config over it again', function () {
    $this->app->instance('config_loaded_from_cache', true);
    config()->set('iam.flag', 'cached');

    $this->app->register(IamServiceProvider::class, force: true);

    expect(config('iam.flag'))->toBe('cached');
});

it('loads the module translations under the module namespace', function () {
    expect(__('iam::messages.hello'))->toBe('Hello from iam');
});

it('keeps the module migrations out of the application migrator, so they never land in its database', function () {
    /** @var Migrator $migrator */
    $migrator = $this->app->make('migrator');

    expect($migrator->paths())
        ->not->toContain(dirname(__DIR__, 2).'/Fixtures/apps/Iam/database/migrations');
});

it('autoloads a module namespace from its app directory, with no composer.json entry', function () {
    expect(class_exists(User::class))->toBeTrue()
        ->and(new ReflectionClass(User::class)->getFileName())
        ->toBe(dirname(__DIR__, 2).'/Fixtures/apps/Iam/app/Models/User.php');
});

it('registers the module console commands', function () {
    $this->artisan('iam:ping')->expectsOutput('pong')->assertSuccessful();
});
