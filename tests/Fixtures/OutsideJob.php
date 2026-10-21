<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A job whose class lives in no module. */
final class OutsideJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function handle(): void {}
}
