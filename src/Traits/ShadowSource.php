<?php

declare(strict_types=1);

namespace Distributable\Traits;

use BackedEnum;
use DateTimeInterface;
use Distributable\Attributes\ShadowFields;
use Distributable\Context\ModuleContext;
use Distributable\Contracts\Stream\Bus;
use Distributable\Events\ShadowChanged;
use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Discovery\ClassAttribute;
use Distributable\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Put on a model other modules keep a copy of. Every write announces the row as it now stands;
 * only the fields #[ShadowFields] names travel, so a field left out never leaves its module.
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static void saved(\Closure $callback)
 * @method static void deleted(\Closure $callback)
 *
 * @mixin Model
 */
trait ShadowSource
{
    public static function bootShadowSource(): void
    {
        static::saved(static function (self $model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged($model->shadowedFields())) {
                $model->announceShadow();
            }
        });

        static::deleted(static fn (self $model) => $model->announceShadow(deleted: true));
    }

    /** The module owning the rows: the one the model's class belongs to. */
    public static function sourceModule(): string
    {
        return app(ModuleRegistry::class)->ownerOf(static::class)->name
            ?? throw ModuleException::outsideModule(static::class);
    }

    /** The stream of distributable.events.streams this model's rows are announced on; override it, null is the default one. */
    public function shadowStream(): ?string
    {
        return null;
    }

    /**
     * A source row deleted for good still reaches the copies, as a soft delete of theirs.
     *
     * @param  list<string>  $keepers  the only modules to update their copy; empty for all of them
     */
    public function announceShadow(bool $deleted = false, array $keepers = []): void
    {
        $attributes = $this->shadowedAttributes();

        if ($deleted && ($attributes['deleted_at'] ?? null) === null) {
            $attributes['deleted_at'] = now()->format('Y-m-d H:i:s');
        }

        app(Bus::class)->emit(new ShadowChanged(
            source: $this->getTable(),
            key: $this->getKey(),
            attributes: $attributes,
            keepers: $keepers,
            stream: $this->shadowStream(),
        ), emitter: static::sourceModule());
    }

    /**
     * Re-announces every row, soft-deleted ones included; a copy already in step is rewritten with itself.
     *
     * @param  list<string>  $keepers
     */
    public static function announceAll(int $chunk = 500, array $keepers = []): int
    {
        $announced = 0;

        app(ModuleContext::class)->within(app(ModuleRegistry::class)->get(static::sourceModule()), static function () use ($chunk, $keepers, &$announced): void {
            static::query()->withoutGlobalScopes()->chunkById($chunk, function ($rows) use (&$announced, $keepers): void {
                foreach ($rows as $row) {
                    $row->announceShadow(keepers: $keepers);
                    $announced++;
                }
            });
        });

        return $announced;
    }

    /** @return list<string> */
    public function shadowedFields(): array
    {
        $dates = array_values(array_filter(
            ['created_at', 'updated_at', 'deleted_at'],
            fn (string $column): bool => array_key_exists($column, $this->getAttributes()),
        ));

        $declared = ClassAttribute::of(static::class, ShadowFields::class)
            ?? throw ConfigurationException::missingAttribute(static::class, ShadowFields::class);

        return [...$declared->fields, ...$dates];
    }

    /** @return array<string, mixed> */
    private function shadowedAttributes(): array
    {
        $attributes = [];

        foreach ($this->shadowedFields() as $field) {
            $value = $this->getAttribute($field);

            $attributes[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        return $attributes;
    }
}
