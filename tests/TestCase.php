<?php

declare(strict_types=1);

namespace Distributable\Tests;

use Distributable\Providers\DistributableServiceProvider;
use Distributable\RpcHttp\RpcHttpServiceProvider;
use Distributable\Stream\Redis\StreamRedisServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            DistributableServiceProvider::class,
            RpcHttpServiceProvider::class, StreamRedisServiceProvider::class,
        ];
    }
}
