<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Providers;

use Distributable\Providers\ServiceProvider;
use Distributable\Tests\Fixtures\Payments\Models\ContactShadow;
use Distributable\Tests\Fixtures\Payments\Rpc\FindPayment;
use Distributable\Tests\Fixtures\Payments\Rpc\FindPaymentHandler;
use Distributable\Tests\Fixtures\Payments\Rpc\PaymentsTraceId;
use Distributable\Tests\Fixtures\Payments\Rpc\PaymentsTraceIdHandler;

final class PaymentsServiceProvider extends ServiceProvider
{
    protected array $rpcHandlers = [
        FindPayment::class => FindPaymentHandler::class,
        PaymentsTraceId::class => PaymentsTraceIdHandler::class,
    ];

    protected array $shadows = [ContactShadow::class];
}
