<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Distributable\Contracts\Rpc\Handler;
use Foundation\Iam\Rpc\CountUsers;
use Illuminate\Support\Facades\DB;

/** @implements Handler<CountUsers> */
final class CountUsersHandler implements Handler
{
    public function handle(CountUsers $message): int
    {
        return DB::table('iam_users')->count();
    }
}
