<?php

declare(strict_types=1);

namespace Foundation\Iam\Services;

use Distributable\Services\Rpc\RpcClient;
use Foundation\Iam\Rpc\CountUsers;
use Foundation\Iam\Rpc\DefaultConnection;
use Foundation\Iam\Rpc\FindUser;
use Foundation\Iam\Rpc\RenameUser;

final class IamClient extends RpcClient
{
    /** @return array{id: int, name: string}|null */
    public function findUser(int $id): ?array
    {
        return $this->readThrough(
            "iam:user:{$id}",
            self::DEFAULT_TTL,
            fn (): mixed => $this->send(new FindUser($id)),
            fn (array $raw): array => ['id' => (int) $raw['id'], 'name' => (string) $raw['name']],
        );
    }

    public function defaultConnection(): string
    {
        return (string) $this->send(new DefaultConnection);
    }

    public function countUsers(): int
    {
        return (int) $this->send(new CountUsers);
    }

    public function renameUser(int $id, string $name): void
    {
        $this->send(new RenameUser($id, $name));
    }
}
