<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Events;

use Distributable\Attributes\Emits;
use Distributable\Events\Event;

#[Emits('orders.order.placed', module: 'orders')]
final class OrderPlaced extends Event
{
    public function __construct(private readonly int $id) {}

    public function payload(): array
    {
        return ['id' => $this->id];
    }
}
