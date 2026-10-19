<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Billing\Services;

use Distributable\Services\Rpc\RpcClient;
use Distributable\Tests\Fixtures\Billing\Rpc\InvoiceFor;

final class BillingClient extends RpcClient
{
    public function invoiceFor(int $orderId): mixed
    {
        return $this->send(new InvoiceFor($orderId));
    }

    /**
     * @param  list<string>|null  $tags
     * @return array<string, mixed>|null
     */
    public function cachedInvoiceFor(int $orderId, ?array $tags = null): ?array
    {
        return $this->readThrough("billing:invoice:{$orderId}", self::DEFAULT_TTL, fn (): mixed => $this->send(new InvoiceFor($orderId)), fn (array $raw): array => $raw, $tags);
    }

    /** @param list<string>|null $tags */
    public function forgetInvoice(int $orderId, ?array $tags = null): void
    {
        $this->forget("billing:invoice:{$orderId}", $tags);
    }
}
