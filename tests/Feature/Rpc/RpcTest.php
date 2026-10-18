<?php

declare(strict_types=1);

use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ServiceException;
use Distributable\Services\Rpc\LocalServices;
use Distributable\Tests\Fixtures\Billing\Contracts\BillingService;
use Distributable\Tests\OrdersAppTestCase;

uses(OrdersAppTestCase::class);

it('refuses a method of a contract the service does not answer', function () {
    app(LocalServices::class)->call('orders', BillingService::class, 'invoiceFor', ['orderId' => 1]);
})->throws(ServiceException::class, 'Service [orders] answers no');

it('names the package of the http driver when it is not installed', function () {
    app(BillingService::class)->invoiceFor(7);
})->throws(ConfigurationException::class, 'composer require mk-josias/laravel-distributable-rpc-http');
