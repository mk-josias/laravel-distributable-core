<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Distributable\Contracts\Rpc\Handler;
use Foundation\Iam\Rpc\TraceId;
use Illuminate\Support\Facades\Context;

/** @implements Handler<TraceId> */
final class TraceIdHandler implements Handler
{
    public function handle(TraceId $message): ?string
    {
        $traceId = Context::get('trace_id');

        return is_string($traceId) ? $traceId : null;
    }
}
