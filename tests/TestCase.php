<?php

declare(strict_types=1);

namespace Distributable\Tests;

use Distributable\Providers\DistributableServiceProvider;
use HttpRpc\HttpRpcServiceProvider;
use Microservices\Providers\MicroservicesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use QueueEventStream\QueueEventStreamServiceProvider;
use RedisEventStream\RedisEventStreamServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            MicroservicesServiceProvider::class, DistributableServiceProvider::class,
            HttpRpcServiceProvider::class, RedisEventStreamServiceProvider::class, QueueEventStreamServiceProvider::class,
        ];
    }
}
