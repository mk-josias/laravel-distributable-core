<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Foundation\Iam\Rpc\FindUserV2;

final class FindUserV2Handler
{
    /** @return array{id: int, name: string, email: string}|null */
    public function handle(FindUserV2 $message): ?array
    {
        return $message->id === 1 ? ['id' => 1, 'name' => 'ada', 'email' => 'ada@example.test'] : null;
    }
}
