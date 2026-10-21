<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Rpc;

use Distributable\Attributes\Rpc;
use Distributable\Contracts\Rpc\Message;

#[Rpc(module: 'payments', name: 'trace-id')]
final readonly class PaymentsTraceId implements Message
{
    public static function rules(): array
    {
        return [];
    }
}
