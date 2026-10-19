<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Foundation\Iam\Rpc\DefaultConnection;
use Illuminate\Support\Facades\DB;

final class DefaultConnectionHandler
{
    public function handle(DefaultConnection $message): string
    {
        return DB::getDefaultConnection();
    }
}
