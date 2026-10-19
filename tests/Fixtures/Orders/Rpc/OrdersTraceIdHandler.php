<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Rpc;

use Distributable\Contracts\Rpc\Handler;
use Illuminate\Support\Facades\Context;

/** @implements Handler<OrdersTraceId> */
final class OrdersTraceIdHandler implements Handler
{
    public function handle(OrdersTraceId $message): ?string
    {
        $trace = Context::get('trace_id');

        return is_string($trace) ? $trace : null;
    }
}
