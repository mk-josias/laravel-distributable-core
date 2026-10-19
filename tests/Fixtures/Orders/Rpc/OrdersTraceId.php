<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Rpc;

use Distributable\Attributes\Rpc;
use Distributable\Contracts\Rpc\Message;

#[Rpc(module: 'orders', name: 'trace-id')]
final readonly class OrdersTraceId implements Message
{
    public static function rules(): array
    {
        return [];
    }
}
