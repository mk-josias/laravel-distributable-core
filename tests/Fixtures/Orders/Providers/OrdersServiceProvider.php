<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Providers;

use Distributable\Providers\RpcServiceProvider;
use Distributable\Tests\Fixtures\Billing\Contracts\BillingService;
use Distributable\Tests\Fixtures\Billing\Services\BillingRpcService;
use Distributable\Tests\Fixtures\Orders\Contracts\OrdersService as OrdersContract;
use Distributable\Tests\Fixtures\Orders\Services\OrdersService;

final class OrdersServiceProvider extends RpcServiceProvider
{
    protected array $rpc = [
        BillingService::class => BillingRpcService::class,
    ];

    protected array $services = [
        OrdersContract::class => OrdersService::class,
    ];
}
