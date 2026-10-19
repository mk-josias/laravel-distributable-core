<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Foundation\Iam\Rpc\FindUser;

final class FindUserHandler
{
    /** @return array{id: int, name: string}|null */
    public function handle(FindUser $message): ?array
    {
        return $message->id === 1 ? ['id' => 1, 'name' => 'ada'] : null;
    }
}
