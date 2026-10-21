<?php

declare(strict_types=1);

namespace Distributable\Providers;

use Distributable\Config\Modules;
use Distributable\Context\ModuleContext;
use Distributable\Context\ModuleMigrator;
use Distributable\Context\ModuleSeeders;
use Distributable\Context\ScheduledTasks;
use Distributable\Contracts\Rpc\Handler;
use Distributable\Contracts\Rpc\Message;
use Distributable\Http\Middleware\SetModuleContext;
use Distributable\Models\ShadowModel;
use Distributable\Services\Discovery\ClassDiscovery;
use Distributable\Services\Rpc\MessageRegistry;
use Distributable\Traits\ResolvesModule;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Base service provider a module extends to get, without manual wiring: its own config
 * (each {module}/config/*.php is the module's alone, applied while it runs; only its config/distributable.php
 * joins the application's),
 * translations ({module}/lang, namespaced by the module name),
 * routes ({module}/routes/{name}.php, prefixed {module}/{name}), console commands and scheduled tasks.
 * Registered only for local modules (DistributableServiceProvider follows RUN_MODULES), so a
 * module's config lands only on the nodes that run it.
 */
abstract class ServiceProvider extends BaseServiceProvider
{
    use ResolvesModule;

    /** @var array<class-string<Message>, class-string<Handler<*>>> each foundation message this module answers, with its handler */
    protected array $rpcHandlers = [];

    /** @var array<class-string, list<string>> as EventServiceProvider::$listen: event => listener classes, or Class@method */
    protected array $listen = [];

    /** @var array<string, list<class-string>> as distributable.events.listen: event name => Handler classes */
    protected array $streamHandlers = [];

    /** @var list<class-string<ShadowModel>> the copies this module keeps: a foundation class, or a subclass of its own */
    protected array $shadows = [];

    public function register(): void
    {
        // A cached config already holds the merge, made while the .env was still loaded.
        if (! $this->app->configurationIsCached()) {
            $this->mergeModuleConfigs();
            $this->mergeModuleHandlers();
            config()->set("distributable.shadows.{$this->module->name}", $this->shadows);
        }

        $this->app->make(MessageRegistry::class)->add($this->module->name, $this->rpcHandlers);
        ModuleSeeders::bind($this->app, $this->module);
    }

    /**
     * A folder of migrations the module's provider loads is the module's own: the other modules do not run it.
     *
     * @param  array<array-key, string>|string  $paths
     */
    protected function loadMigrationsFrom($paths)
    {
        $module = $this->module->name;

        $this->callAfterResolving('migrator', static function (ModuleMigrator $migrator) use ($module, $paths): void {
            foreach ((array) $paths as $path) {
                $migrator->modulePath($module, $path);
            }
        });
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleTranslations();
        $this->loadModuleViews();
        $this->registerModuleCommands();
        $this->registerModuleListeners();
        $this->registerModuleSchedule();
    }

    /** The module's scheduled tasks: only a process that runs the module schedules them, and each runs in it. */
    protected function schedule(Schedule $schedule): void {}

    private function registerModuleSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $declared = count($schedule->events());
            $this->schedule($schedule);

            foreach (array_slice($schedule->events(), $declared) as $task) {
                $this->app->make(ScheduledTasks::class)->assign($task, $this->module->name);
            }
        });
    }

    /** Views, anonymous components and class components, all under the module name: view('iam::welcome'), <x-iam::alert />. */
    private function loadModuleViews(): void
    {
        $views = $this->module->path().'/resources/views';
        $module = $this->module;

        if (is_dir($views)) {
            $this->loadViewsFrom($views, $module->name);
        }

        $this->callAfterResolving(BladeCompiler::class, static function (BladeCompiler $blade) use ($views, $module): void {
            $blade->componentNamespace($module->namespace.'\\View\\Components', $module->name);

            if (is_dir($views.'/components')) {
                $blade->anonymousComponentPath($views.'/components', $module->name);
            }
        });
    }

    /** Each listener is built as Laravel builds it and runs in this module, an after-commit one once the transaction commits. */
    private function registerModuleListeners(): void
    {
        $module = $this->module;

        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                $handle = Event::makeListener($listener);
                [$class] = Str::parseCallback($listener);
                $afterCommit = is_subclass_of($class, ShouldHandleEventsAfterCommit::class)
                    || (get_class_vars($class)['afterCommit'] ?? false) === true;

                Event::listen($event, static function (mixed ...$payload) use ($module, $handle, $event, $afterCommit): mixed {
                    $app = Container::getInstance();
                    $run = static fn (): mixed => $app->make(ModuleContext::class)->within($module, static fn (): mixed => $handle($event, $payload));

                    if ($afterCommit && $app->bound('db.transactions')) {
                        $app->make('db.transactions')->addCallback($run);

                        return null;
                    }

                    return $run();
                });
            }
        }
    }

    private function mergeModuleConfigs(): void
    {
        $configPath = $this->module->path().'/config';

        // The application's own config/ when the module's folder is the application's: nothing to merge.
        if (! is_dir($configPath) || realpath($configPath) === realpath(config_path())) {
            return;
        }

        foreach (glob($configPath.'/*.php') ?: [] as $configFile) {
            $key = pathinfo($configFile, PATHINFO_FILENAME);
            $values = (array) require $configFile;

            // A file the process reads whole, by name: the modules' values add up.
            if (in_array($key, array_map(strval(...), (array) config('distributable.shared', [])), true)) {
                config()->set($key, $this->deepMerge((array) config($key, []), $values));

                continue;
            }

            // Everything else is the module's own, applied while it runs: each value replaces the application's, a list or [] included.
            config()->set("distributable.overlays.{$this->module->name}.{$key}", $values);
        }
    }

    private function mergeModuleHandlers(): void
    {
        $listen = (array) config('distributable.events.listen', []);

        foreach ($this->streamHandlers as $event => $handlers) {
            $listen[$event] = array_values(array_unique([...$listen[$event] ?? [], ...$handlers]));
        }

        config()->set('distributable.events.listen', $listen);
    }

    /**
     * A list gains the items it lacks; any other array merges key by key, integer keys included.
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<array-key, mixed>  $override
     * @return array<array-key, mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        if (array_is_list($base) && array_is_list($override)) {
            foreach ($override as $value) {
                if (! in_array($value, $base, true)) {
                    $base[] = $value;
                }
            }

            return $base;
        }

        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
                ? $this->deepMerge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    private function loadModuleRoutes(): void
    {
        $module = $this->module;

        // The application's own routes/, loaded by bootstrap/app.php.
        if (realpath($module->path().'/routes') === realpath(base_path('routes'))) {
            return;
        }

        $router = $this->app->make(Router::class);
        $config = $this->app->make(Modules::class);

        // routes/{name}.php → distributable.routes.prefix ({module}/{name}/… by default), wrapped in the
        // `{name}` middleware group when the application defines one, which runs in the module.
        foreach (glob($module->path().'/routes/*.php') ?: [] as $routeFile) {
            $name = basename($routeFile, '.php');
            $middleware = $router->hasMiddlewareGroup($name) ? [$name] : [];

            Route::prefix($config->getRoutePrefix($module->name, $name))
                ->middleware([SetModuleContext::class.':'.$module->name, ...$middleware])
                ->group($routeFile);
        }
    }

    private function loadModuleTranslations(): void
    {
        $langPath = $this->module->path().'/lang';

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->module->name);
        }
    }

    private function registerModuleCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands(ClassDiscovery::with(Command::class, $this->module->classPath()));
    }
}
