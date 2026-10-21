<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Models;

use Distributable\Attributes\Shadow;
use Distributable\Models\ShadowModel;

/** Messaging's contacts, as payments keeps them: the table contacts. */
#[Shadow(module: 'messaging', table: 'contacts', migrations: [__DIR__.'/../database/shadows/contacts'])]
final class ContactShadow extends ShadowModel
{
    protected static function shouldBeShadowed(array $attributes): bool
    {
        return ($attributes['name'] ?? null) !== 'Hidden';
    }
}
