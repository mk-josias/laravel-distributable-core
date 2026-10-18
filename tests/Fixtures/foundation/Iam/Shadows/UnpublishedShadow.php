<?php

declare(strict_types=1);

namespace Foundation\Iam\Shadows;

use Distributable\Models\ShadowModel;

/** A copy whose owner names no migration: modules:doctor reports it. */
final class UnpublishedShadow extends ShadowModel
{
    public static function owner(): string
    {
        return 'iam';
    }

    public static function sourceTable(): string
    {
        return 'iam_roles';
    }
}
