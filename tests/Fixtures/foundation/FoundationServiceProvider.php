<?php

declare(strict_types=1);

namespace Foundation;

use Distributable\Providers\FoundationServiceProvider as BaseServiceProvider;
use Foundation\Iam\Events\UserRenamedPayload;

final class FoundationServiceProvider extends BaseServiceProvider
{
    protected array $payloads = [
        UserRenamedPayload::class,
    ];
}
