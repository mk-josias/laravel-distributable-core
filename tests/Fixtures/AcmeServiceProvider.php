<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

/** A third-party package that publishes its migration and its config, and loads nothing. */
final class AcmeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishesMigrations([__DIR__.'/packages/acme/migrations' => database_path('migrations')], 'acme-migrations');
        $this->publishes([__DIR__.'/packages/acme/config/acme.php' => config_path('acme.php')], 'acme-config');
    }
}
