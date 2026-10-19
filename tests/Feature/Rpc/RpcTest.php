<?php

declare(strict_types=1);

use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Rpc\MessageRegistry;
use Distributable\Tests\Fixtures\Billing\Services\BillingClient;
use Distributable\Tests\Fixtures\Orders\Rpc\FindOrder;
use Distributable\Tests\Fixtures\Orders\Rpc\OrdersTraceIdHandler;
use Distributable\Tests\OrdersAppTestCase;

uses(OrdersAppTestCase::class);

it('refuses a message the module does not answer', function () {
    app(MessageRegistry::class)->answer('orders', 'invoice-for', 1, ['orderId' => 1]);
})->throws(ModuleException::class, 'Module [orders] answers no [invoice-for]');

it('names the package of the http driver when it is not installed', function () {
    app(BillingClient::class)->invoiceFor(7);
})->throws(ConfigurationException::class, 'composer require mk-josias/laravel-distributable-rpc-http');

it('refuses a handler that does not implement Handler', function () {
    app(MessageRegistry::class)->add('orders', [FindOrder::class => stdClass::class]);
})->throws(ConfigurationException::class, 'does not implement');

it('refuses a handler whose handle() takes another message', function () {
    app(MessageRegistry::class)->add('orders', [FindOrder::class => OrdersTraceIdHandler::class]);
})->throws(ConfigurationException::class, 'must declare handle(');
