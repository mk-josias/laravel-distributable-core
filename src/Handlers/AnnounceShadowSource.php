<?php

declare(strict_types=1);

namespace Distributable\Handlers;

use Distributable\Contracts\Colocation;
use Distributable\Contracts\Stream\Handler;
use Distributable\Contracts\Stream\Idempotent;
use Distributable\Services\Shadows\ShadowRegistry;

/** Answers a copy that asks again: every row is re-announced to the asking service alone. */
final readonly class AnnounceShadowSource implements Handler, Idempotent
{
    public function __construct(
        private ShadowRegistry $catalog,
        private Colocation $colocation,
    ) {}

    public function handle(string $name, array $payload): void
    {
        $this->catalog->sourceOf((string) $payload['source'], $this->colocation->current())
            ?->announceAll(keepers: [(string) $payload['keeper']]);
    }
}
