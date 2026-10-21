<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

use Distributable\Attributes\Emits;
use Distributable\Events\Event;

/** Travels on the `audit` stream the module declares in its config/distributable.php. */
#[Emits('iam.user.audited', stream: 'audit')]
final class UserAudited extends Event
{
    public function __construct(private readonly int $id) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['id' => $this->id];
    }
}
