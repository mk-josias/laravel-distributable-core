<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Distributable\Contracts\Rpc\Handler;
use Foundation\Iam\Rpc\DefaultConnection;
use Illuminate\Support\Facades\DB;

/** @implements Handler<DefaultConnection> */
final class DefaultConnectionHandler implements Handler
{
    public function handle(DefaultConnection $message): string
    {
        return DB::getDefaultConnection();
    }
}
