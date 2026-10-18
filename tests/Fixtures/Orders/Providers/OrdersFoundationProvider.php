<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Providers;

use Distributable\Providers\FoundationServiceProvider;
use Distributable\Tests\Fixtures\Billing\Contracts\BillingService;
use Distributable\Tests\Fixtures\Billing\Services\BillingRpcService;

/** What orders calls: billing, which runs elsewhere. */
final class OrdersFoundationProvider extends FoundationServiceProvider
{
    protected array $rpc = [
        BillingService::class => BillingRpcService::class,
    ];
}
