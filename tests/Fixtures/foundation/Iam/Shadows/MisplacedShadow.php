<?php

declare(strict_types=1);

namespace Foundation\Iam\Shadows;

use Distributable\Contracts\PublishesMigrations;
use Distributable\Models\ShadowModel;

/** A copy naming a migrations folder that does not exist: modules:doctor reports it. */
final class MisplacedShadow extends ShadowModel implements PublishesMigrations
{
    public static function owner(): string
    {
        return 'iam';
    }

    public static function sourceTable(): string
    {
        return 'iam_groups';
    }

    public static function migrations(): array
    {
        return [__DIR__.'/../database/shadows/groups'];
    }
}
