<?php

declare(strict_types=1);

namespace Distributable\Context;

use ArrayObject;
use Closure;
use Distributable\Config\Modules;
use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\FileViewFinder;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;

/**
 * Which module the running request, job or command belongs to; its connection becomes the
 * default one, so a plain DB::transaction() or query lands in that module's database. Outside a
 * module with a database the default is NO_MODULE, whose every query fails.
 */
final class ModuleContext
{
    public const string CONTEXT_KEY = 'distributable.module';

    /** The default connection of the module the job was queued in: a job whose module is gone still knows where it ran. */
    public const string CONNECTION_KEY = 'distributable.connection';

    public const string NO_MODULE = 'no_module';

    private const string SERVICES = 'distributable.module_services';

    private const string CONFIG = 'distributable.module_config';

    /** @var list<string|null> the module each open request or command found running */
    private array $entered = [];

    /** @var array{app: string, database: string, config: string, views: mixed, namespace: string|null}|null the application's paths while a module runs */
    private ?array $application = null;

    public function __construct(private readonly ModuleRegistry $registry) {}

    public function switchTo(?Module $module): void
    {
        // The running application, not the one this singleton was built in: Octane serves each
        // request from its own copy, with its own config.
        $config = Container::getInstance()->make(Repository::class);
        $entered = $this->swapConfig($config, $module);

        $config->set('database.default', $module !== null && $module->hasDatabase
            ? $module->defaultConnection()
            : self::NO_MODULE);

        $this->swapServices($module, (array) $config->get('distributable.module_services', []));
        $this->swapPaths($module);

        if ($entered) {
            $this->configState()['entered'] = $this->items($config)();
        }

        $module !== null
            ? Context::addHidden(self::CONTEXT_KEY, $module->name)
            : Context::forgetHidden(self::CONTEXT_KEY);

        if ($module !== null && $module->hasDatabase) {
            Context::addHidden(self::CONNECTION_KEY, Queue\Databases::for($module, Queue\Databases::FAILED));
        }
    }

    /**
     * The context that leaves keeps what it changed: the application its values as they are now, a module what it set while it ran.
     * The one that enters gets the application's values, then, for a module, its own on top: what it writes replaces the application's.
     *
     * @return bool whether a module was entered
     */
    private function swapConfig(Repository $config, ?Module $module): bool
    {
        $state = $this->configState();
        $items = $this->items($config);
        $leaving = $state['owner'];
        $entering = $module?->name;

        if ($leaving === $entering) {
            return false;
        }

        if ($leaving === null) {
            $state['application'] = $items();
        } else {
            $own = $state['own'];
            $own[$leaving] = [...$this->own($leaving), ...$this->changed((array) $state['entered'], $items())];
            $state['own'] = $own;
        }

        $items((array) $state['application']);
        $state['owner'] = $entering;

        if ($entering === null) {
            $state['application'] = $state['entered'] = null;

            return false;
        }

        $config->set($this->own($entering));

        return true;
    }

    /** @return array<string, mixed> what the module sets for itself: its config files (ServiceProvider), then what it changed while it ran */
    private function own(string $module): array
    {
        $state = $this->configState();
        $own = $state['own'];

        if (! isset($own[$module])) {
            $own[$module] = ModuleConfig::leaves((array) Arr::get((array) $state['application'], "distributable.overlays.{$module}", []));
            $state['own'] = $own;
        }

        return $own[$module];
    }

    /**
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return array<string, mixed> each value set or changed since $before
     */
    private function changed(array $before, array $after): array
    {
        if ($before === $after) {
            return [];
        }

        $old = ModuleConfig::leaves($before);

        return array_filter(ModuleConfig::leaves($after), static fn (mixed $value, string $path): bool => ! array_key_exists($path, $old) || $old[$path] !== $value, ARRAY_FILTER_USE_BOTH);
    }

    /** @return ArrayObject<string, mixed> kept in the running application: an Octane copy has its own */
    private function configState(): ArrayObject
    {
        $app = Application::getInstance();

        if (! $app->bound(self::CONFIG)) {
            $app->instance(self::CONFIG, new ArrayObject(['owner' => null, 'application' => null, 'entered' => null, 'own' => []]));
        }

        return $app->make(self::CONFIG);
    }

    /**
     * Reads every value of the config, or replaces them all: Laravel's repository offers neither.
     *
     * @return Closure(array<array-key, mixed>|null=): array<array-key, mixed>
     */
    private function items(Repository $config): Closure
    {
        return Closure::bind(fn (?array $set = null): array => $set === null ? $this->items : $this->items = $set, $config, \Illuminate\Config\Repository::class);
    }

    /**
     * What the module that leaves has built is put aside; the one that enters gets back its own, and builds the rest as make()
     * builds it (deferred provider, extenders) with its config, on first use. Kept in the running application: an Octane copy builds its own.
     *
     * @param  array<array-key, mixed>  $services
     */
    private function swapServices(?Module $module, array $services): void
    {
        if ($services === []) {
            return;
        }

        $app = Application::getInstance();

        if (! $app->bound(self::SERVICES)) {
            $app->instance(self::SERVICES, new ArrayObject(['current' => '', 'built' => []]));
        }

        /** @var ArrayObject<string, mixed> $state */
        $state = $app->make(self::SERVICES);
        $owner = $module->name ?? '';
        $current = (string) $state['current'];

        if ($owner === $current) {
            return;
        }

        /** @var array<string, array<string, mixed>> $built */
        $built = $state['built'];
        $instance = Closure::bind(fn (string $id): mixed => $this->instances[$id] ?? null, $app, Container::class);

        foreach ($services as $id) {
            if (! is_string($id)) {
                continue;
            }

            if (($held = $instance($id)) !== null) {
                $built[$current][$id] = $held;
            }

            $app->forgetInstance($id);

            if (isset($built[$owner][$id])) {
                $app->instance($id, $built[$owner][$id]);
            }

            Facade::clearResolvedInstance($id);
        }

        $state['built'] = $built;
        $state['current'] = $owner;

        if ($owner !== '') {
            $this->teach($app, $built[''] ?? []);
        }

        // Eloquent keeps the manager it was given at boot.
        if (in_array('db', $services, true)) {
            Model::setConnectionResolver($app->make('db'));
        }
    }

    /**
     * What the application taught a service in code (a driver given to extend(), a validation rule) is a closure it keeps in an
     * array: the module's own instance, built with the module's config, gets each one it lacks, whenever the application learned it.
     *
     * @param  array<string, mixed>  $application  the services the application holds
     */
    private function teach(Application $app, array $application): void
    {
        foreach ($application as $id => $source) {
            if (! is_object($source) || ($learned = self::learned($source)) === []) {
                continue;
            }

            $target = $app->make($id);

            if (! is_object($target) || $target::class !== $source::class) {
                continue;
            }

            foreach ($learned as [$property, $closures]) {
                /** @var array<array-key, mixed> $own */
                $own = $property->getValue($target);

                foreach ($closures as $key => $closure) {
                    if (! array_key_exists($key, $own)) {
                        $own[$key] = $closure instanceof Closure && (new ReflectionFunction($closure))->getClosureThis() === $source ? $closure->bindTo($target) : $closure;
                    }
                }

                $property->setValue($target, $own);
            }
        }
    }

    /**
     * The arrays of $service that hold a closure, with their content.
     *
     * @return list<array{ReflectionProperty, array<array-key, mixed>}>
     */
    private static function learned(object $service): array
    {
        $learned = [];

        for ($class = new ReflectionClass($service); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->isReadOnly() || $property->getDeclaringClass()->name !== $class->name || ! $property->isInitialized($service)) {
                    continue;
                }

                $value = $property->getValue($service);

                if (is_array($value) && array_filter($value, static fn (mixed $item): bool => $item instanceof Closure) !== []) {
                    $learned[] = [$property, $value];
                }
            }
        }

        return $learned;
    }

    /**
     * app_path(), database_path(), config_path() and the application namespace are the module's while it runs, and its
     * views come before the application's: a command or a package that reads them (model:prune, make:model, a package's
     * scan) works in the module.
     */
    private function swapPaths(?Module $module): void
    {
        $app = Application::getInstance();
        $config = $app->make('config');

        // Laravel offers no setter for the namespace it caches.
        $namespace = Closure::bind(fn (?string ...$set): ?string => $set === [] ? $this->namespace : $this->namespace = $set[0], $app, Application::class);

        if ($module === null && $this->application !== null) {
            $paths = $this->application;
            $this->application = null;
        } elseif ($module !== null) {
            $this->application ??= ['app' => $app->path(), 'database' => $app->databasePath(), 'config' => $app->configPath(), 'views' => $config->get('view.paths'), 'namespace' => $namespace()];
            $paths = ['app' => $module->classPath(), 'database' => $module->path().'/database', 'config' => $module->path().'/config', 'views' => [$module->path().'/resources/views', ...(array) $this->application['views']], 'namespace' => $module->namespace.'\\'];
        } else {
            return;
        }

        $app->useAppPath($paths['app']);
        $app->useDatabasePath($paths['database']);
        $app->useConfigPath($paths['config']);
        $config->set('view.paths', $paths['views']);
        $namespace($paths['namespace']);

        // The view finder reads view.paths once, when it is built.
        if ($app->resolved('view') && ($finder = $app->make('view')->getFinder()) instanceof FileViewFinder) {
            $finder->setPaths((array) $paths['views']);
            $finder->flush();
        }
    }

    /** database_path() of the application, whichever module runs. */
    public function applicationDatabasePath(string $path = ''): string
    {
        $base = $this->application['database'] ?? Application::getInstance()->databasePath();

        return $path === '' ? $base : $base.DIRECTORY_SEPARATOR.$path;
    }

    /** Where a path of the application lies in the module that runs: under its app/, database/, config/ or views; any other path stays. */
    public function modulePath(string $path): string
    {
        $module = $this->current();

        if ($module === null || $this->application === null) {
            return $path;
        }

        $roots = [
            $this->application['app'] => $module->classPath(),
            $this->application['database'] => $module->path().'/database',
            $this->application['config'] => $module->path().'/config',
        ];

        foreach ((array) $this->application['views'] as $views) {
            $roots[(string) $views] = $module->path().'/resources/views';
        }

        foreach ($roots as $root => $own) {
            if ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                return $own.substr($path, strlen($root));
            }
        }

        return $path;
    }

    /** The connection of module $name, opened in it: only there is it configured. */
    public function connection(string $name): Connection
    {
        $module = $this->registry->get($name);

        return $this->within($module, fn (): Connection => Container::getInstance()->make('db')->connection($this->registry->connectionOf($name)));
    }

    public function switchToModuleOf(string $class): void
    {
        $this->switchTo($this->registry->forClass($class));
    }

    /** A request or a command starts in $module; leave() gives back the module it found running. */
    public function enter(?Module $module): void
    {
        $this->entered[] = $this->current()?->name;
        $this->switchTo($module);
    }

    public function leave(): void
    {
        if ($this->entered === []) {
            return;
        }

        $name = array_pop($this->entered);
        $this->switchTo($name === null ? null : $this->registry->find($name));
    }

    /**
     * Wraps $task so it runs in the current module wherever it runs later: after the response, in
     * another worker, in a child process. Only the module's name is captured, so the task stays
     * serializable.
     *
     * @param  Closure(): mixed  $task
     * @return Closure(): mixed
     */
    public function bind(Closure $task): Closure
    {
        $module = $this->current()?->name;

        return static function () use ($module, $task): mixed {
            $context = Container::getInstance()->make(self::class);

            return $context->within($module === null ? null : $context->registry->find($module), $task);
        };
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(?Module $module, Closure $callback): mixed
    {
        if ($module !== null) {
            $this->refuseAnotherModuleCaller($module);
        }

        $previous = $this->current();
        $this->switchTo($module);

        try {
            return $callback();
        } finally {
            $this->switchTo($previous);
        }
    }

    /**
     * The code that asks for $module, past the package's relays, is the package, a test or $module's own:
     * a module or the foundation entering another module would read its database behind its contract.
     */
    private function refuseAnotherModuleCaller(Module $module): void
    {
        $relays = [dirname(__DIR__).'/Testing/ModuleAware.php'];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || $file === __FILE__ || in_array($file, $relays, true)) {
                continue;
            }

            foreach ($this->registry->all() as $owner) {
                if ($owner->name !== $module->name && str_starts_with($file, $owner->classPath().DIRECTORY_SEPARATOR)) {
                    throw ModuleException::entersAnotherModule($owner->name, $module->name, $file);
                }
            }

            if (str_starts_with($file, Container::getInstance()->make(Modules::class)->getFoundationPath().DIRECTORY_SEPARATOR)) {
                throw ModuleException::entersAnotherModule('foundation', $module->name, $file);
            }

            return;
        }
    }

    public function current(): ?Module
    {
        $name = Context::getHidden(self::CONTEXT_KEY);

        return is_string($name) ? $this->registry->find($name) : null;
    }
}
