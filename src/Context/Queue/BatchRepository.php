<?php

declare(strict_types=1);

namespace Distributable\Context\Queue;

use DateTimeInterface;
use Distributable\Context\ModuleContext;
use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Context;

/**
 * Stores a batch in the database of the module owning its jobs — a batch belongs to one module.
 * While a job runs, the provider points the repository at that job's module (Queue::before);
 * reads enter each module this process runs and read its database.
 */
final class BatchRepository extends DatabaseBatchRepository
{
    public const string CONTEXT_KEY = 'distributable.batch_module';

    public function __construct(
        BatchFactory $factory,
        string $table,
        private readonly ModuleRegistry $registry,
    ) {
        // Only until store() or find() names the module.
        parent::__construct($factory, self::open(Databases::all($registry)[0] ?? throw ModuleException::noModuleRunning()), $table);
    }

    /** The module's connection, opened in the module: only there is it configured. */
    private static function open(Module $module): Connection
    {
        return Container::getInstance()->make(ModuleContext::class)->within(
            $module,
            static fn (): Connection => Container::getInstance()->make('db')->connection(Databases::for($module, Databases::BATCHING)),
        );
    }

    public function useModuleOf(string $class): void
    {
        $module = $this->registry->forClass($class);

        if ($module !== null && $module->hasDatabase) {
            $this->setConnection(self::open($module));
        }
    }

    public function store(PendingBatch $batch)
    {
        $first = $batch->jobs->first();

        $class = match (true) {
            is_object($first) => $first::class,
            is_array($first) && isset($first[0]) && is_object($first[0]) => $first[0]::class,
            is_string($first) => $first,
            default => '',
        };

        $running = Context::getHidden(ModuleContext::CONTEXT_KEY);
        $module = $this->registry->forClass($class) ?? (is_string($running) ? $this->registry->find($running) : null);

        if ($module === null || ! $module->hasDatabase) {
            throw ModuleException::noModuleRunning();
        }

        $this->setConnection(self::open($module));
        Context::addHidden(self::CONTEXT_KEY, $module->name);

        return parent::store($batch);
    }

    public function find(string $batchId)
    {
        foreach ($this->searchOrder() as $module) {
            $this->setConnection(self::open($module));

            if ($this->connection->table($this->table)->where('id', $batchId)->exists()) {
                break;
            }
        }

        return parent::find($batchId);
    }

    /**
     * The module that stored the batch comes first when the job carries it; the others stay as a fallback.
     *
     * @return list<Module>
     */
    private function searchOrder(): array
    {
        $modules = Databases::all($this->registry);
        $stored = Context::getHidden(self::CONTEXT_KEY);
        $first = array_values(array_filter($modules, static fn (Module $module): bool => $module->name === $stored));

        return [...$first, ...array_values(array_filter($modules, static fn (Module $module): bool => $module->name !== $stored))];
    }

    /** @return list<Batch> */
    public function get($limit = 50, $before = null)
    {
        $batches = [];

        foreach (Databases::modules($this->registry) as $module) {
            $this->setConnection(self::open($module));
            $batches = [...$batches, ...parent::get($limit, $before)];
        }

        usort($batches, fn (Batch $a, Batch $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice($batches, 0, $limit);
    }

    public function cancel(string $batchId)
    {
        if ($this->find($batchId) !== null) {
            parent::cancel($batchId);
        }
    }

    public function delete(string $batchId)
    {
        if ($this->find($batchId) !== null) {
            parent::delete($batchId);
        }
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->summed(fn (): int => parent::prune($before));
    }

    public function pruneUnfinished(DateTimeInterface $before)
    {
        return $this->summed(fn (): int => parent::pruneUnfinished($before));
    }

    public function pruneCancelled(DateTimeInterface $before)
    {
        return $this->summed(fn (): int => parent::pruneCancelled($before));
    }

    /** @param callable(): int $callback */
    private function summed(callable $callback): int
    {
        $total = 0;

        foreach (Databases::modules($this->registry) as $module) {
            $this->setConnection(self::open($module));
            $total += $callback();
        }

        return $total;
    }
}
