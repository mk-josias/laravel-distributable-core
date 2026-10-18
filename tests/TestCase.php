<?php

declare(strict_types=1);

namespace Distributable\Tests;

use Distributable\Providers\DistributableServiceProvider;
use Distributable\Providers\MicroservicesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RpcHttp\RpcHttpServiceProvider;
use StreamQueue\StreamQueueServiceProvider;
use StreamRedis\StreamRedisServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            MicroservicesServiceProvider::class, DistributableServiceProvider::class,
            RpcHttpServiceProvider::class, StreamRedisServiceProvider::class, StreamQueueServiceProvider::class,
        ];
    }
}
