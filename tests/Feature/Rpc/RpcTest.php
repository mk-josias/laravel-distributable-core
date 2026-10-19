<?php

declare(strict_types=1);

use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ServiceException;
use Distributable\Services\Rpc\Messages;
use Distributable\Tests\Fixtures\Billing\Services\BillingClient;
use Distributable\Tests\OrdersAppTestCase;

uses(OrdersAppTestCase::class);

it('refuses a message the module does not answer', function () {
    app(Messages::class)->answer('orders', 'invoice-for', 1, ['orderId' => 1]);
})->throws(ServiceException::class, 'Module [orders] answers no [invoice-for]');

it('names the package of the http driver when it is not installed', function () {
    app(BillingClient::class)->invoiceFor(7);
})->throws(ConfigurationException::class, 'composer require mk-josias/laravel-distributable-rpc-http');
