<?php

declare(strict_types=1);

namespace Distributable\Contracts\Rpc;

/** A transport that answers calls in a process of its own, started by `rpc:serve`. */
interface ServesRpc
{
    /** Answers calls through MessageRegistry::answer() until the process stops; the exit code. */
    public function serve(): int;
}
