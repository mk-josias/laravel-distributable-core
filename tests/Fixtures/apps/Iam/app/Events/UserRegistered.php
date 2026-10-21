<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

use Distributable\Attributes\Emits;
use Distributable\Events\Event;

#[Emits(IamEvent::UserRegistered)]
final class UserRegistered extends Event
{
    public function __construct(
        private readonly int $id,
        private readonly string $userName,
    ) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['id' => $this->id, 'name' => $this->userName];
    }
}
