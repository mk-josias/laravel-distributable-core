<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

use Distributable\Attributes\Emits;
use Distributable\Events\Event;
use Foundation\Iam\Events\UserRenamedPayload;

#[Emits(UserRenamedPayload::NAME)]
final class UserRenamed extends Event
{
    public function __construct(private readonly int $id, private readonly string $fullName) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['id' => $this->id, 'full_name' => $this->fullName, 'locale' => 'fr'];
    }
}
