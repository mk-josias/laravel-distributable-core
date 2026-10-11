<?php

declare(strict_types=1);

namespace Apps\Gateway\Providers;

use Illuminate\Support\ServiceProvider;

final class GatewayTokensProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('gateway.tokens', 'registered');
    }
}
