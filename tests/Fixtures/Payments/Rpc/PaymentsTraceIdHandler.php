<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Rpc;

use Distributable\Contracts\Rpc\Handler;
use Illuminate\Support\Facades\Context;

/** @implements Handler<PaymentsTraceId> */
final class PaymentsTraceIdHandler implements Handler
{
    public function handle(PaymentsTraceId $message): ?string
    {
        $trace = Context::get('trace_id');

        return is_string($trace) ? $trace : null;
    }
}
