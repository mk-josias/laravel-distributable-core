<?php

declare(strict_types=1);

namespace Distributable\Providers;

use Composer\Autoload\ClassLoader;
use Distributable\Config\Modules;
use Distributable\Console\Commands\AnnounceShadows;
use Distributable\Console\Commands\CacheModules;
use Distributable\Console\Commands\ClearModules;
use Distributable\Console\Commands\ConsumeEvents;
use Distributable\Console\Commands\DeleteModule;
use Distributable\Console\Commands\Doctor;
use Distributable\Console\Commands\ExportEvents;
use Distributable\Console\Commands\ImportEvents;
use Distributable\Console\Commands\Install;
use Distributable\Console\Commands\ListModules;
use Distributable\Console\Commands\MakeModule;
use Distributable\Console\Commands\PruneConsumptions;
use Distributable\Console\Commands\PurgeModules;
use Distributable\Console\Commands\RepublishEvents;
use Distributable\Console\Commands\ServeRpc;
use Distributable\Console\Commands\SkipEntries;
use Distributable\Console\Commands\TrimEvents;
use Distributable\Console\Commands\UnusedPackages;
use Distributable\Console\Commands\WantShadows;
use Distributable\Console\ModuleGenerators;
use Distributable\Console\ModuleOption;
use Distributable\Console\ModulePublishCommand;
use Distributable\Context\CurrentDatabase;
use Distributable\Context\ModuleConcurrencyDriver;
use Distributable\Context\ModuleContext;
use Distributable\Context\ModuleDeferredCallbacks;
use Distributable\Context\ModuleFactories;
use Distributable\Context\ModuleMigrator;
use Distributable\Context\ModuleTaskDispatcher;
use Distributable\Context\NoModuleConnection;
use Distributable\Context\Queue\BatchRepository;
use Distributable\Context\Queue\FailedJobProvider;
use Distributable\Context\ScheduledTasks;
use Distributable\Contracts\Rpc\Transport as RpcTransport;
use Distributable\Contracts\Stream\Bus;
use Distributable\Contracts\Stream\Transport;
use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Http\Controllers\StatusController;
use Distributable\Services\Discovery\DiscoveryCache;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Rpc\MessageRegistry;
use Distributable\Services\Rpc\ModuleRpcTransport;
use Distributable\Services\Rpc\Rpc as RpcSender;
use Distributable\Services\Rpc\TransportManager as RpcTransportManager;
use Distributable\Services\Shadows\CachedShadowRegistry;
use Distributable\Services\Shadows\ShadowRegistry;
use Distributable\Services\Stream\ArchiveStores;
use Distributable\Services\Stream\Emitter;
use Distributable\Services\Stream\PayloadVersions;
use Distributable\Services\Stream\QueuedHandlers;
use Distributable\Services\Stream\TransportManager;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository as BatchRepositoryContract;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Octane\Contracts\DispatchesTasks;

final class DistributableServiceProvider extends BaseServiceProvider
{
    private static bool $guardsRemoteModules = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/distributable.php', 'distributable');

        // The keys exist, empty, so that a module that sets one keeps it for itself (ServiceProvider overlays).
        $this->app['config']->set(['queue.failed.database' => null, 'queue.batching.database' => null]);
        $this->mergeSections(['rpc', 'events']);

        // Every database belongs to a module: until one runs, the default connection refuses every query.
        $this->app['config']->set('database.connections.'.ModuleContext::NO_MODULE, ['driver' => ModuleContext::NO_MODULE]);
        $this->app['config']->set('database.default', ModuleContext::NO_MODULE);

        // Built without touching a database, so a service Laravel builds on the default connection exists; its first query throws.
        $this->app->afterResolving('db', static fn (DatabaseManager $db) => $db->extend(
            ModuleContext::NO_MODULE,
            static fn () => new NoModuleConnection,
        ));

        $this->carryTheModuleIntoDeferredWork();

        // A message to a module this process runs is answered directly; any other goes out on the module's transport.
        $this->app->bind(RpcTransport::class, ModuleRpcTransport::class);
        $this->app->singleton(RpcTransportManager::class);
        $this->app->singleton(MessageRegistry::class);
        $this->app->singleton(RpcSender::class);

        $this->app->singleton(TransportManager::class);
        $this->app->singleton(ArchiveStores::class);
        $this->app->singleton(PayloadVersions::class);
        $this->app->singleton(QueuedHandlers::class);
        $this->app->singleton(Bus::class, Emitter::class);
        // Injecting the contract yields the default stream; the manager stays the seam where a driver is added with extend().
        $this->app->bind(Transport::class, static fn (Application $app): Transport => $app->make(TransportManager::class)->stream());

        $this->app->singleton(ShadowRegistry::class, CachedShadowRegistry::class);

        $this->app->singleton(DiscoveryCache::class);

        // What the folder of a declared module tells is read from the cache when there is one.
        $this->app->singleton(ModuleRegistry::class, static function (Application $app): ModuleRegistry {
            $config = $app->make(Modules::class);
            $cached = array_column($app->make(DiscoveryCache::class)->load()['modules'] ?? [], null, 'name');

            return new ModuleRegistry(array_map(
                static fn (string $name): Module => isset($cached[$name])
                    ? Module::fromArray($cached[$name])
                    : $config->module($name),
                $config->getDeclaredModules(),
            ), $config->getLoadedModules());
        });

        $this->app->singleton(ModuleContext::class);
        $this->registerQueueDatabases();
        $this->registerModuleMigrations();
        ModuleFactories::register();

        $this->app->singleton(ModuleGenerators::class);
        $this->app->singleton(ScheduledTasks::class);
        $this->app->afterResolving(Command::class, ModuleOption::addTo(...));
        $this->app->extend(VendorPublishCommand::class, static fn (mixed $command, Application $app): ModulePublishCommand => new ModulePublishCommand($app['files']));

        // On the booting callback: after every provider (and the app's own configuration)
        // has registered, before any provider boots — module providers slot in between.
        $this->app->booting(function (): void {
            $this->registerLocalModuleProviders();
        });
    }

    /** Work started in a module but run later or elsewhere (defer, Concurrency, Octane tasks) keeps that module. */
    private function carryTheModuleIntoDeferredWork(): void
    {
        $this->app->scoped(DeferredCallbackCollection::class, ModuleDeferredCallbacks::class);

        $this->app->afterResolving(ConcurrencyManager::class, static function (ConcurrencyManager $manager, Application $app): void {
            $manager->extend('process', fn (Application $app): ModuleConcurrencyDriver => new ModuleConcurrencyDriver(
                new ProcessDriver($app->make(ProcessFactory::class)),
                $app->make(ModuleContext::class),
            ));
        });

        if (interface_exists(DispatchesTasks::class)) {
            $this->app->bind(DispatchesTasks::class, ModuleTaskDispatcher::class);
        }
    }

    /** Laravel's own migrate:* commands, in the module they run in: its database, its folders. */
    private function registerModuleMigrations(): void
    {
        // Built once, for every command that holds them: they reach the database of the module they run in.
        $this->app->extend('migration.repository', static fn (mixed $repository, Application $app): DatabaseMigrationRepository => new DatabaseMigrationRepository(new CurrentDatabase, (string) ($app['config']['database.migrations.table'] ?? $app['config']['database.migrations'])));
        $this->app->extend('migrator', static fn (mixed $migrator, Application $app): ModuleMigrator => new ModuleMigrator($app['migration.repository'], new CurrentDatabase, $app['files'], $app['events']));
    }

    /** Failed jobs and batches live in the database of the module owning the job, when Laravel stores them in a database. */
    private function registerQueueDatabases(): void
    {
        // There is no application database: a failed job or a batch goes where its module is, and queue.failed.database and queue.batching.database are not read.
        $this->app->extend('queue.failer', static fn (mixed $failer, Application $app): mixed => config('queue.failed.driver') === 'database-uuids'
            ? new FailedJobProvider(
                $app->make('db'),
                (string) config('queue.failed.table', 'failed_jobs'),
                $app->make(ModuleRegistry::class),
            )
            : $failer);

        $this->app->singleton(BatchRepository::class, static fn (Application $app): BatchRepository => new BatchRepository(
            $app->make(BatchFactory::class),
            (string) config('queue.batching.table', 'job_batches'),
            $app->make(ModuleRegistry::class),
        ));

        $this->app->extend(BatchRepositoryContract::class, static fn (mixed $repository, Application $app): mixed => $repository instanceof DatabaseBatchRepository
            ? $app->make(BatchRepository::class)
            : $repository);
    }

    /** A route, job or command runs in the context of the module owning its code, batch counters included. */
    private function switchContextOnJobsAndCommands(): void
    {
        // Written on each route once every route is in, while a closure still knows its file: route:cache keeps it.
        $this->app->booted(function (): void {
            $registry = $this->app->make(ModuleRegistry::class);

            foreach ($this->app->make('router')->getRoutes()->getRoutes() as $route) {
                if (($module = $registry->forRoute($route)) !== null) {
                    $route->setAction([...$route->getAction(), 'module' => $module->name]);
                }
            }
        });

        // Wherever the route is declared: a module's own file, routes/web.php or another package.
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            $app = Container::getInstance();
            $context = $app->make(ModuleContext::class);
            $context->enter($app->make(ModuleRegistry::class)->forRoute($event->route) ?? $context->current());
        });

        // A request or a command run inside another (a test, Octane, Artisan::call) gives its module back.
        Event::listen(RequestHandled::class, fn () => $this->app->make(ModuleContext::class)->leave());
        Event::listen(CommandFinished::class, fn () => $this->app->make(ModuleContext::class)->leave());

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = $this->app->make(ConsoleKernel::class)->all()[$event->command] ?? null;
            $context = $this->app->make(ModuleContext::class);
            // A command no module owns, called from a module (Artisan::call), runs in that module, as a route does.
            $context->enter(($command === null ? null : $this->app->make(ModuleRegistry::class)->forClass($command::class)) ?? $context->current());

            // --module on any command (db:seed, tinker, model:show…) names the module it starts in; ModuleOption runs it in each module named, * in all.
            if (is_string($named = $event->input->getParameterOption('--module', null)) && $named !== '*') {
                $this->app->make(ModuleContext::class)->switchTo($this->app->make(ModuleRegistry::class)->get($named));
            }
        });

        // schedule:run is no module's command: a task runs in the module that declared it, then leaves it.
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $module = $this->app->make(ScheduledTasks::class)->moduleOf($event->task);
            $this->app->make(ModuleContext::class)->switchTo($module === null ? null : $this->app->make(ModuleRegistry::class)->get($module));
        });

        Event::listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], fn () => $this->app->make(ModuleContext::class)->switchTo(null));

        Queue::before(function (JobProcessing $event): void {
            $context = $this->app->make(ModuleContext::class);

            // Laravel has just restored the Context the job was dispatched with, module included:
            // a job class outside every module (a queued closure) runs in the module that queued it.
            $context->switchTo($this->app->make(ModuleRegistry::class)->forClass($event->job->resolveName()) ?? $context->current());

            if ($this->app->resolved(BatchRepositoryContract::class)
                && ($repository = $this->app->make(BatchRepositoryContract::class)) instanceof BatchRepository) {
                $repository->useModuleOf($event->job->resolveName());
            }
        });
    }

    /** Registers the service provider of every module this process boots (RUN_MODULES). */
    private function registerLocalModuleProviders(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $this->autoloadModules($registry);

        foreach ($registry->local() as $module) {
            if (! is_dir($module->path())) {
                throw ModuleException::missingFolder($module->name, $module->path());
            }

            // class_exists keeps the app bootable while a module's provider does not exist yet.
            foreach ($module->providers() as $provider) {
                if (class_exists($provider)) {
                    $this->app->register($provider);
                }
            }
        }

    }

    /**
     * Autoloads the modules this process runs, with no composer.json entry. A
     * class of a module running elsewhere is never loaded: using one throws instead of reading
     * code this process must not depend on — whether that code is still on disk or was purged.
     */
    private function autoloadModules(ModuleRegistry $registry): void
    {
        $loader = new ClassLoader;
        $config = $this->app->make(Modules::class);

        foreach ($registry->local() as $module) {
            $loader->addPsr4($module->namespace.'\\', $module->classPath());
            $loader->addPsr4($module->namespace.'\\Database\\Factories\\', $module->path().'/database/factories');
            $loader->addPsr4($module->namespace.'\\Database\\Seeders\\', $module->path().'/database/seeders');
            $loader->addPsr4($module->namespace.'\\Tests\\', $module->path().'/tests');
        }

        $loader->register();

        if (! self::$guardsRemoteModules) {
            self::$guardsRemoteModules = true;

            // Prepended, so it answers before any loader that could still find the file; the registry is read per call.
            spl_autoload_register(static function (string $class): void {
                if (! app()->resolved(ModuleRegistry::class)) {
                    return;
                }

                $registry = app(ModuleRegistry::class);
                $module = $registry->forClass($class);

                if ($module !== null && ! $registry->isLocal($module->name)) {
                    throw ModuleException::notLocal($class, $module->name);
                }
            }, prepend: true);
        }
    }

    /**
     * An application's `'events' => ['listen' => …]` keeps the shipped streams and tables.
     *
     * @param  list<string>  $sections
     */
    private function mergeSections(array $sections): void
    {
        if ($this->app instanceof \Illuminate\Foundation\Application && $this->app->configurationIsCached()) {
            return;
        }

        $defaults = require __DIR__.'/../../config/distributable.php';
        $config = $this->app->make('config');

        foreach ($sections as $section) {
            $config->set("distributable.{$section}", array_replace($defaults[$section], (array) $config->get("distributable.{$section}", [])));
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/distributable.php' => config_path('distributable.php'),
        ], 'distributable-config');

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->switchContextOnJobsAndCommands();

        $statusRoute = $this->app->make(Modules::class)->getStatusRoute();

        if ($statusRoute !== null) {
            Route::get($statusRoute, StatusController::class)->name('distributable.status');
        }

        $this->optimizes(optimize: 'modules:cache', clear: 'modules:clear', key: 'distributable');

        if ($this->app->runningInConsole()) {
            Event::listen(CommandStarting::class, fn (CommandStarting $event) => $this->app->make(ModuleGenerators::class)->starting($event));
            Event::listen(CommandFinished::class, fn (CommandFinished $event) => $this->app->make(ModuleGenerators::class)->finished($event));

            $this->commands([
                Install::class, MakeModule::class, DeleteModule::class, ListModules::class, Doctor::class, PurgeModules::class, UnusedPackages::class,
                CacheModules::class, ClearModules::class,
                RepublishEvents::class, ConsumeEvents::class, SkipEntries::class, TrimEvents::class, PruneConsumptions::class, ServeRpc::class,
                ExportEvents::class, ImportEvents::class, AnnounceShadows::class, WantShadows::class,
            ]);
        }
    }
}
