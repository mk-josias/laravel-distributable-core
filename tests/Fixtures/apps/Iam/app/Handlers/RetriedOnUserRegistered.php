<?php

declare(strict_types=1);

namespace Apps\Iam\Handlers;

use Distributable\Contracts\Stream\Handler;
use Distributable\Tests\Doubles\Recorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class RetriedOnUserRegistered implements Handler, ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly Recorder $recorder) {}

    public function handle(string $name, array $payload): void
    {
        $this->recorder->records[] = [$name, $payload];
        $this->recorder->traces[] = $this->job?->resolveName();
    }
}
