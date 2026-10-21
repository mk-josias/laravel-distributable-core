<?php

declare(strict_types=1);

namespace Distributable\Contracts\Stream;

/**
 * The single seam modules emit events through. The binding decides what emitting means —
 * straight to the transport, or through the outbox — and module code never knows which.
 * The emitter is the module the event class belongs to, or the one its #[Emits] names, unless given.
 */
interface Bus
{
    public function emit(Event $event, ?string $emitter = null): void;
}
