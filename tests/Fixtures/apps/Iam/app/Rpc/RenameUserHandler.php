<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Foundation\Iam\Rpc\RenameUser;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RenameUserHandler
{
    public function handle(RenameUser $message): void
    {
        DB::transaction(function () use ($message): void {
            DB::table('iam_users')->where('id', $message->id)->update(['name' => $message->name]);

            if (DB::transactionLevel() !== 1) {
                throw new LogicException('The transaction is not open on the default connection.');
            }
        });
    }
}
