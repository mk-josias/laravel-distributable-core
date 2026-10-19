<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Billing\Rpc;

use Distributable\Attributes\Rpc;
use Distributable\Contracts\Rpc\Message;

#[Rpc(module: 'billing')]
final readonly class InvoiceFor implements Message
{
    public function __construct(public int $orderId) {}

    public static function rules(): array
    {
        return ['orderId' => ['required', 'integer']];
    }
}
