<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Rpc;

use Distributable\Contracts\Rpc\Handler;
use Distributable\Tests\Fixtures\Orders\Models\Order;

/** @implements Handler<FindOrder> */
final class FindOrderHandler implements Handler
{
    /** @return array{id: int, total: int}|null */
    public function handle(FindOrder $message): ?array
    {
        $order = Order::query()->find($message->id);

        return $order === null ? null : ['id' => $order->id, 'total' => $order->total];
    }
}
