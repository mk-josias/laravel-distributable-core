<?php

declare(strict_types=1);

namespace Distributable\Context\Queue;

use DateTimeInterface;
use Distributable\Context\ModuleContext;
use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Context;

/**
 * Stores a failed job in the module it ran in; reads enter each module this process runs and read its database.
 * It never picks a module for a job: without one, it refuses.
 */
final class FailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function __construct(
        ConnectionResolverInterface $resolver,
        string $table,
        private readonly ModuleRegistry $registry,
    ) {
        // Every operation names its module (in()); a call that does not reaches NO_MODULE, which refuses it.
        parent::__construct($resolver, ModuleContext::NO_MODULE, $table);
    }

    public function log($connection, $queue, $payload, $exception)
    {
        [$module, $database] = $this->whereTheJobRan();

        return $this->in($module, fn () => parent::log($connection, $queue, $payload, $exception), $database);
    }

    /** @return array<int, mixed> */
    public function ids($queue = null)
    {
        return $this->merged(fn () => parent::ids($queue));
    }

    /** @return array<int, mixed> */
    public function all()
    {
        return $this->merged(fn () => parent::all());
    }

    public function find($id)
    {
        foreach ($this->modules() as $module) {
            $record = $this->in($module, fn () => parent::find($id));

            if ($record !== null) {
                return $record;
            }
        }

        return null;
    }

    public function forget($id)
    {
        foreach ($this->modules() as $module) {
            if ($this->in($module, fn () => parent::forget($id))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Laravel 13 added $queue: the arguments go through as given, so 12 gets only $hours.
     *
     * @param  int|null  $hours
     * @param  string|null  $queue
     */
    public function flush($hours = null, $queue = null)
    {
        $arguments = func_get_args();

        foreach ($this->modules() as $module) {
            $this->in($module, fn () => parent::flush(...$arguments));
        }
    }

    public function prune(DateTimeInterface $before)
    {
        return array_sum(array_map(fn (Module $module): int => $this->in($module, fn () => parent::prune($before)), $this->modules()));
    }

    public function count($connection = null, $queue = null)
    {
        return array_sum(array_map(fn (Module $module): int => $this->in($module, fn () => parent::count($connection, $queue)), $this->modules()));
    }

    /**
     * The job's module, which Queue::before has switched to; for one that is gone, the connection its job carried, in the module that declares it.
     *
     * @return array{Module, string|null}
     */
    private function whereTheJobRan(): array
    {
        $name = Context::getHidden(ModuleContext::CONTEXT_KEY);
        $module = is_string($name) ? $this->registry->find($name) : null;

        if ($module !== null && $module->hasDatabase) {
            return [$module, null];
        }

        $carried = Context::getHidden(ModuleContext::CONNECTION_KEY);

        foreach ($this->registry->local() as $candidate) {
            if (is_string($carried) && in_array($carried, $candidate->connections(), true)) {
                return [$candidate, $carried];
            }
        }

        throw ModuleException::noModuleRunning();
    }

    /** @return list<Module> */
    private function modules(): array
    {
        return Databases::modules($this->registry);
    }

    /**
     * @param  callable(): iterable<int, mixed>  $callback
     * @return array<int, mixed>
     */
    private function merged(callable $callback): array
    {
        $merged = [];

        foreach ($this->modules() as $module) {
            $merged = [...$merged, ...$this->in($module, $callback)];
        }

        return $merged;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function in(Module $module, callable $callback, ?string $connection = null): mixed
    {
        return Container::getInstance()->make(ModuleContext::class)->within($module, function () use ($module, $callback, $connection): mixed {
            $previous = [$this->resolver, $this->database];
            $this->resolver = Container::getInstance()->make('db');
            $this->database = $connection ?? Databases::for($module, Databases::FAILED);

            try {
                return $callback();
            } finally {
                [$this->resolver, $this->database] = $previous;
            }
        });
    }
}
