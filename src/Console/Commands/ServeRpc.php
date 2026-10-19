<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Contracts\Rpc\ServesRpc;
use Distributable\Services\Rpc\TransportManager;
use Illuminate\Console\Command;

final class ServeRpc extends Command
{
    protected $signature = 'rpc:serve
        {--transport= : The transport to serve (default: distributable.rpc.transport)}';

    protected $description = 'Answer the calls of a transport in a process of its own.';

    public function handle(TransportManager $transports): int
    {
        $transport = $transports->transport($this->option('transport') !== null ? (string) $this->option('transport') : null);

        if (! $transport instanceof ServesRpc) {
            $this->error('This transport has no process of its own: the application answers its calls.');

            return self::FAILURE;
        }

        return $transport->serve();
    }
}
