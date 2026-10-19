<?php

declare(strict_types=1);

namespace Distributable\Providers;

use Distributable\Contracts\Stream\Versioned;
use Distributable\Services\Stream\PayloadVersions;
use Illuminate\Support\ServiceProvider;

/**
 * Base of the provider of a foundation: what it declares for every module. The application lists it
 * in bootstrap/providers.php, or a foundation package in extra.laravel.providers.
 * A module answers its own messages in its provider's $rpc.
 */
abstract class FoundationServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string<Versioned>> event name => the payload class that declares its versions */
    protected array $payloads = [];

    public function boot(): void
    {
        $this->app->make(PayloadVersions::class)->add($this->payloads);
    }
}
