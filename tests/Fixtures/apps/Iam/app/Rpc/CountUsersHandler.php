<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Foundation\Iam\Rpc\CountUsers;
use Illuminate\Support\Facades\DB;

final class CountUsersHandler
{
    public function handle(CountUsers $message): int
    {
        return DB::table('iam_users')->count();
    }
}
