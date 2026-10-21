<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Providers;

use Distributable\Providers\ServiceProvider;
use Distributable\Tests\Fixtures\Orders\Models\CustomerShadow;
use Distributable\Tests\Fixtures\Orders\Rpc\FindOrder;
use Distributable\Tests\Fixtures\Orders\Rpc\FindOrderHandler;
use Distributable\Tests\Fixtures\Orders\Rpc\OrdersTraceId;
use Distributable\Tests\Fixtures\Orders\Rpc\OrdersTraceIdHandler;

final class OrdersServiceProvider extends ServiceProvider
{
    protected array $rpcHandlers = [
        FindOrder::class => FindOrderHandler::class,
        OrdersTraceId::class => OrdersTraceIdHandler::class,
    ];

    protected array $shadows = [CustomerShadow::class];
}
