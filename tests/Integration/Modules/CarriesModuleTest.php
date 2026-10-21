<?php

declare(strict_types=1);

use Apps\Analytics\Reports\EntersIam;
use ArrayObject;
use Carbon\CarbonInterval;
use Distributable\Context\ModuleConcurrencyDriver;
use Distributable\Context\ModuleContext;
use Distributable\Context\ModuleTaskDispatcher;
use Distributable\Exceptions\ModuleException;
use Distributable\Tests\Doubles\DeferredReportsProvider;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\SerializableClosure\SerializableClosure;

uses(ModuleAppTestCase::class);

it('refuses a module code that enters another module', function () {
    $analytics = app(EntersIam::class);

    expect(fn () => $analytics->directly('iam'))->toThrow(ModuleException::class, 'makes analytics code run in module [iam]')
        ->and($analytics->directly('analytics'))->toBe('entered')
        ->and($this->inModule('iam', static fn (): string => 'entered'))->toBe('entered');
});

it('gives each module its own instance of a module_services service, built once and swapped in as it runs', function () {
    config()->set('distributable.module_services', ['cache', 'cache.store']);

    $this->inModule('iam', fn () => Cache::put('owner', 'iam'));
    $this->inModule('analytics', fn () => Cache::put('owner', 'analytics'));
    $iam = $this->inModule('iam', fn () => app('cache'));

    expect($this->inModule('iam', fn () => Cache::get('owner')))->toBe('iam')
        ->and($this->inModule('analytics', fn () => Cache::get('owner')))->toBe('analytics')
        ->and(Cache::get('owner'))->toBeNull()
        ->and($this->inModule('iam', fn () => app('cache')))->toBe($iam);
});

it('teaches a module the drivers the application learned before it first runs, on a store of its own', function () {
    config()->set('cache.stores.custom', ['driver' => 'custom']);
    Cache::extend('custom', fn () => Cache::repository(new ArrayStore));
    Cache::store('custom')->put('owner', 'application');

    expect($this->inModule('iam', fn () => Cache::store('custom')->get('owner')))->toBeNull()
        ->and(Cache::store('custom')->get('owner'))->toBe('application');
});

it('teaches a module that already ran the drivers the application learned since, and keeps what it opened', function () {
    $this->inModule('iam', fn () => Cache::put('owner', 'iam'));
    config()->set('cache.stores.custom', ['driver' => 'custom']);
    Cache::extend('custom', fn () => Cache::repository(new ArrayStore));

    expect($this->inModule('iam', fn () => Cache::store('custom')->put('key', 'value')))->toBeTrue()
        ->and($this->inModule('iam', fn () => Cache::get('owner')))->toBe('iam');
});

it('keeps a driver a module learns to that module', function () {
    config()->set('cache.stores.custom', ['driver' => 'custom']);
    $this->inModule('iam', fn () => Cache::extend('custom', fn () => Cache::repository(new ArrayStore)));

    expect($this->inModule('iam', fn () => Cache::store('custom')->put('key', 'value')))->toBeTrue()
        ->and(fn () => Cache::store('custom'))->toThrow(InvalidArgumentException::class, 'Driver [custom] is not supported.')
        ->and(fn () => $this->inModule('analytics', fn () => Cache::store('custom')))->toThrow(InvalidArgumentException::class, 'Driver [custom] is not supported.');
});

it('teaches a module what the application taught any service in code, a disk driver or a validation rule', function () {
    config()->set('filesystems.disks.custom', ['driver' => 'custom', 'root' => sys_get_temp_dir()]);
    Storage::extend('custom', fn ($app, array $config) => Storage::createLocalDriver($config));
    Validator::extend('even', fn (string $attribute, mixed $value): bool => $value % 2 === 0);

    expect($this->inModule('iam', fn () => Storage::disk('custom')->path('file')))->toBe(sys_get_temp_dir().'/file')
        ->and($this->inModule('iam', fn () => Validator::make(['n' => 2], ['n' => 'even'])->passes()))->toBeTrue();
});

it('builds a module_services service of a deferred provider on the first switch, through its extenders', function () {
    app()->addDeferredServices(['reports' => DeferredReportsProvider::class]);
    app()->extend('reports', static function (ArrayObject $reports): ArrayObject {
        $reports['extended'] = true;

        return $reports;
    });
    config()->set('distributable.module_services', ['reports']);

    $iam = $this->inModule('iam', fn () => app('reports'));
    $analytics = $this->inModule('analytics', fn () => app('reports'));

    expect($iam)->not->toBe($analytics)
        ->and($iam['extended'] ?? false)->toBeTrue()
        ->and($analytics['extended'] ?? false)->toBeTrue()
        ->and($this->inModule('iam', fn () => app('reports')))->toBe($iam);
});

it('runs a scheduled closure in the module that declared it, then leaves the module', function () {
    $this->artisan('schedule:run')->assertSuccessful();

    expect($this->inModule('iam', fn () => Cache::get('scheduled-connection')))->toBe('iam')
        ->and(DB::getDefaultConnection())->toBe(ModuleContext::NO_MODULE);
});

it('runs a queued closure in the module that queued it', function () {
    $this->inModule('iam', static function (): void {
        dispatch(static fn () => Cache::put('connection', DB::getDefaultConnection()));
    });

    expect($this->inModule('iam', fn () => Cache::get('connection')))->toBe('iam');
});

it('runs a deferred callback in the module that deferred it, after that module handed back', function () {
    $this->inModule('iam', fn () => defer(fn () => Cache::put('connection', DB::getDefaultConnection())));

    expect(DB::getDefaultConnection())->not->toBe('iam');

    app(DeferredCallbackCollection::class)->invoke();

    expect($this->inModule('iam', fn () => Cache::get('connection')))->toBe('iam');
});

it('gives a task its module, even serialized and run where no module is set', function () {
    // One closure per line: SerializableClosure finds a closure's code by its line.
    $read = static fn (): string => DB::getDefaultConnection();
    $task = $this->inModule('iam', static fn () => app(ModuleContext::class)->bind($read));

    /** @var Closure(): string $elsewhere */
    $elsewhere = unserialize(serialize(new SerializableClosure($task)))->getClosure();

    expect(app(ModuleContext::class)->current())->toBeNull()
        ->and($elsewhere())->toBe('iam')
        ->and(app(ModuleContext::class)->current())->toBeNull();
});

it('gives each Concurrency task the module of its caller', function () {
    $isolated = new class implements Driver
    {
        public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
        {
            app(ModuleContext::class)->switchTo(null); // a child process starts with no module

            return array_map(fn (Closure $task): mixed => $task(), (array) $tasks);
        }

        public function defer(Closure|array $tasks): DeferredCallback
        {
            return new DeferredCallback(fn () => null);
        }
    };

    $driver = new ModuleConcurrencyDriver($isolated, app(ModuleContext::class));

    expect($this->inModule('iam', fn () => $driver->run([fn (): string => DB::getDefaultConnection()])))->toBe(['iam']);
});

it('hands Octane a dispatcher that binds each task to its module', function () {
    expect(app(DispatchesTasks::class))->toBeInstanceOf(ModuleTaskDispatcher::class)
        ->and($this->inModule('iam', fn () => app(DispatchesTasks::class)->resolve(['a' => fn (): string => DB::getDefaultConnection()])))
        ->toBe(['a' => 'iam']);
});
