<?php

declare(strict_types=1);

use Distributable\Exceptions\ModuleException;
use Distributable\Tests\OnlyGatewayRunsTestCase;

uses(OnlyGatewayRunsTestCase::class);

it('refuses a failed job when no module that runs has a database, instead of using an application database', function () {
    app('queue.failer')->log('sync', 'default', json_encode(['uuid' => 'f-5', 'displayName' => 'Apps\\Gone\\Job']), new RuntimeException('boom'));
})->throws(ModuleException::class);
