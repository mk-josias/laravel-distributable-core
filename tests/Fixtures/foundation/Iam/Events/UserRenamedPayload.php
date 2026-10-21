<?php

declare(strict_types=1);

namespace Foundation\Iam\Events;

use Distributable\Attributes\Payload;
use Distributable\Contracts\Stream\Versioned;

/** Version 1 carried `name`, version 2 renamed it `full_name`, version 3 added `locale`. */
#[Payload(UserRenamedPayload::NAME, version: 3)]
final class UserRenamedPayload implements Versioned
{
    public const string NAME = 'iam.user.renamed';

    public static function upcast(int $from, array $payload): array
    {
        return match ($from) {
            1 => ['id' => $payload['id'], 'full_name' => $payload['name']],
            2 => [...$payload, 'locale' => 'en'],
            default => $payload,
        };
    }
}
