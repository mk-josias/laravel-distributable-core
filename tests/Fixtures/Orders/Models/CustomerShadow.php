<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Models;

use Distributable\Models\ShadowModel;

/** Billing's customers, as orders keeps them: the table customers. */
final class CustomerShadow extends ShadowModel
{
    public static function owner(): string
    {
        return 'billing';
    }

    public static function sourceTable(): string
    {
        return 'customers';
    }

    public static function migrations(): array
    {
        return [__DIR__.'/../database/shadows/customers'];
    }

    protected static function shouldBeShadowed(array $attributes): bool
    {
        return ($attributes['name'] ?? null) !== 'Hidden';
    }
}
