<?php

declare(strict_types=1);

namespace Distributable\Handlers;

use Distributable\Context\ModuleContext;
use Distributable\Contracts\Stream\Handler;
use Distributable\Contracts\Stream\Idempotent;
use Distributable\Services\ModuleRegistry;
use Distributable\Services\Shadows\ShadowRegistry;

/** Writes an announced source row into the copies of the consuming module, or of every local keeper, each in its own context. */
final readonly class SyncShadows implements Handler, Idempotent
{
    public function __construct(
        private ShadowRegistry $catalog,
        private ModuleRegistry $registry,
        private ModuleContext $context,
    ) {}

    public function handle(string $name, array $payload): void
    {
        $source = (string) $payload['source'];
        /** @var array<string, mixed> $attributes */
        $attributes = (array) ($payload['attributes'] ?? []);
        $key = is_int($payload['key']) ? $payload['key'] : (string) $payload['key'];
        $current = $this->context->current()?->name;

        foreach ($current !== null ? [$current] : $this->catalog->keepersOf($source) as $keeper) {
            $this->context->within($this->registry->get($keeper), function () use ($source, $keeper, $key, $attributes): void {
                foreach ($this->catalog->shadowsOf($source, $keeper) as $shadow) {
                    $shadow::sync($key, $attributes);
                }
            });
        }
    }
}
