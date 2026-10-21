<?php

declare(strict_types=1);

use Distributable\Tests\TestCase;

uses(TestCase::class);

use Distributable\Config\Modules;

it('merges the distributable config with its defaults, and the microservices one next to it', function () {
    expect(config('distributable.declared'))->toBe([])
        ->and(config('distributable.runs'))->toBe('*')
        ->and(config('distributable.modules.path'))->toBe('apps')
        ->and(config('distributable.modules.namespace'))->toBe('Apps')
        ->and(config('distributable.foundation.path'))->toBe('foundation')
        ->and(config('distributable.events.streams.default.driver'))->toBe('array')
        ->and(config('distributable.rpc.transport'))->toBeNull();
});

it('reads RUN_MODULES as a list of module names, * standing for every module', function () {
    config()->set('distributable.runs', ' transactions , analytics ,');

    expect(app(Modules::class)->getLoadedModules())->toBe(['transactions', 'analytics']);

    config()->set('distributable.runs', '*');

    expect(app(Modules::class)->getLoadedModules())->toBe(['*']);
});
