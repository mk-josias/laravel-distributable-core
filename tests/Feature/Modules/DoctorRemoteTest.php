<?php

declare(strict_types=1);

use Distributable\Tests\WithoutIamTestCase;

uses(WithoutIamTestCase::class);

it('fails on a module running elsewhere that serves messages but has no host', function () {
    config()->set('distributable.declared.iam', []);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('[iam] runs elsewhere and serves RPC messages')
        ->assertFailed();
});
