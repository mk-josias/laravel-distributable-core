<?php

declare(strict_types=1);

use Distributable\Exceptions\ConfigurationException;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Rpc\MessageRegistry;
use Distributable\Tests\Fixtures\Messaging\Services\MessagingClient;
use Distributable\Tests\Fixtures\Payments\Rpc\FindPayment;
use Distributable\Tests\Fixtures\Payments\Rpc\PaymentsTraceIdHandler;
use Distributable\Tests\PaymentsAppTestCase;

uses(PaymentsAppTestCase::class);

it('refuses a message the module does not answer', function () {
    app(MessageRegistry::class)->answer('payments', 'conversation-for', 1, ['paymentId' => 1]);
})->throws(ModuleException::class, 'Module [payments] answers no [conversation-for]');

it('names the package of the http driver when it is not installed', function () {
    app(MessagingClient::class)->conversationFor(7);
})->throws(ConfigurationException::class, 'composer require mk-josias/laravel-distributable-rpc-http');

it('refuses a handler that does not implement Handler', function () {
    app(MessageRegistry::class)->add('payments', [FindPayment::class => stdClass::class]);
})->throws(ConfigurationException::class, 'does not implement');

it('refuses a handler whose handle() takes another message', function () {
    app(MessageRegistry::class)->add('payments', [FindPayment::class => PaymentsTraceIdHandler::class]);
})->throws(ConfigurationException::class, 'must declare handle(');
