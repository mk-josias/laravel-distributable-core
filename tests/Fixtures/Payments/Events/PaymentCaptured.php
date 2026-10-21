<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Events;

use Distributable\Attributes\Emits;
use Distributable\Events\Event;

#[Emits('payments.payment.captured', module: 'payments')]
final class PaymentCaptured extends Event
{
    public function __construct(private readonly int $id) {}

    public function payload(): array
    {
        return ['id' => $this->id];
    }
}
