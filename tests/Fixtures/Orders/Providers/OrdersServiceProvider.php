<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Providers;

use Distributable\Providers\ServiceProvider;
use Distributable\Tests\Fixtures\Orders\Contracts\OrdersService as OrdersContract;
use Distributable\Tests\Fixtures\Orders\Models\CustomerShadow;
use Distributable\Tests\Fixtures\Orders\Services\OrdersService;

final class OrdersServiceProvider extends ServiceProvider
{
    protected array $services = [
        OrdersContract::class => OrdersService::class,
    ];

    protected array $shadows = [CustomerShadow::class];
}
