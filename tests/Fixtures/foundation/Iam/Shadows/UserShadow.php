<?php

declare(strict_types=1);

namespace Foundation\Iam\Shadows;

use Distributable\Contracts\Shadows\PublishesMigrations;
use Distributable\Models\ShadowModel;

/** The shape of a copy of iam's users, declared once by iam for every module that keeps one. */
class UserShadow extends ShadowModel implements PublishesMigrations
{
    public static function owner(): string
    {
        return 'iam';
    }

    public static function sourceTable(): string
    {
        return 'iam_users';
    }

    public static function migrations(): array
    {
        return [__DIR__.'/../database/shadows/users'];
    }
}
