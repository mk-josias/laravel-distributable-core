<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Distributable\Contracts\Rpc\Handler;
use Foundation\Iam\Rpc\FindUser;

/** @implements Handler<FindUser> */
final class FindUserHandler implements Handler
{
    /** @return array{id: int, name: string}|null */
    public function handle(FindUser $message): ?array
    {
        return $message->id === 1 ? ['id' => 1, 'name' => 'ada'] : null;
    }
}
