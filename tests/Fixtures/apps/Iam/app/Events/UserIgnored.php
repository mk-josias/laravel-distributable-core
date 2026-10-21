<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

use Distributable\Attributes\Emits;
use Distributable\Events\Event;

/** Emitted by the module, listened to by nobody — the no-subscriber path. */
#[Emits('iam.user.ignored')]
final class UserIgnored extends Event
{
    public function __construct(private readonly int $id) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['id' => $this->id];
    }
}
