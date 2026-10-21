<?php

declare(strict_types=1);

namespace Apps\Iam\Handlers;

use Distributable\Contracts\Stream\Handler;
use Distributable\Tests\Doubles\Recorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class QueuedOnUserRegistered implements Handler, ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly Recorder $recorder) {}

    public function handle(string $name, array $payload): void
    {
        $this->recorder->records[] = [$name, $payload];
        $this->recorder->connections[] = DB::getDefaultConnection();
    }
}
