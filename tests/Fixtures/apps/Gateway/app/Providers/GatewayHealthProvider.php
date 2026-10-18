<?php

declare(strict_types=1);

namespace Apps\Gateway\Providers;

use Illuminate\Support\ServiceProvider;

final class GatewayHealthProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('gateway.health', 'registered');
    }
}
