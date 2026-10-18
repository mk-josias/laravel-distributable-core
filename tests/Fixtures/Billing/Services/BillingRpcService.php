<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Billing\Services;

use Distributable\Services\Rpc\RpcService;
use Distributable\Tests\Fixtures\Billing\Contracts\BillingService;

final class BillingRpcService extends RpcService implements BillingService
{
    public function invoiceFor(int $orderId): ?array
    {
        /** @var array{order_id: int, amount: int}|null */
        return $this->call('invoiceFor', ['orderId' => $orderId]);
    }

    /**
     * @param  list<string>|null  $tags
     * @return array<string, mixed>|null
     */
    public function cachedInvoiceFor(int $orderId, ?array $tags = null): ?array
    {
        return $this->readThrough("billing:invoice:{$orderId}", self::DEFAULT_TTL, fn (): mixed => $this->call('invoiceFor', ['orderId' => $orderId]), fn (array $raw): array => $raw, $tags);
    }

    /** @param list<string>|null $tags */
    public function forgetInvoice(int $orderId, ?array $tags = null): void
    {
        $this->forget("billing:invoice:{$orderId}", $tags);
    }
}
