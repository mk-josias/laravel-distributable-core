<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Models;

use Distributable\Attributes\Shadow;
use Distributable\Models\ShadowModel;

/** Billing's customers, as orders keeps them: the table customers. */
#[Shadow(module: 'billing', table: 'customers', migrations: [__DIR__.'/../database/shadows/customers'])]
final class CustomerShadow extends ShadowModel
{
    protected static function shouldBeShadowed(array $attributes): bool
    {
        return ($attributes['name'] ?? null) !== 'Hidden';
    }
}
