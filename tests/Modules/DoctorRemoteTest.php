<?php

declare(strict_types=1);

use Distributable\Tests\Support\WithoutIamTestCase;

uses(WithoutIamTestCase::class);

it('fails on a module running elsewhere that serves a contract but has no host', function () {
    config()->set('modules.declared.iam', []);

    $this->artisan('modules:doctor')
        ->expectsOutputToContain('[iam] runs elsewhere and serves Foundation\Iam\Contracts\IamService')
        ->assertFailed();
});
