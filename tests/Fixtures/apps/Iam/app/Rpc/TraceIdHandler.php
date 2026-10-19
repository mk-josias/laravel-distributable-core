<?php

declare(strict_types=1);

namespace Apps\Iam\Rpc;

use Foundation\Iam\Rpc\TraceId;
use Illuminate\Support\Facades\Context;

final class TraceIdHandler
{
    public function handle(TraceId $message): ?string
    {
        $traceId = Context::get('trace_id');

        return is_string($traceId) ? $traceId : null;
    }
}
