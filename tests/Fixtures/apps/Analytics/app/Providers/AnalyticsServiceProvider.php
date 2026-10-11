<?php

declare(strict_types=1);

namespace Apps\Analytics\Providers;

use Apps\Analytics\Models\UserShadow;
use Distributable\Providers\ServiceProvider;

final class AnalyticsServiceProvider extends ServiceProvider
{
    protected array $shadows = [UserShadow::class];
}
