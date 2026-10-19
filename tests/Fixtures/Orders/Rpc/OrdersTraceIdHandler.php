<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Rpc;

use Illuminate\Support\Facades\Context;

final class OrdersTraceIdHandler
{
    public function handle(OrdersTraceId $message): ?string
    {
        $trace = Context::get('trace_id');

        return is_string($trace) ? $trace : null;
    }
}
