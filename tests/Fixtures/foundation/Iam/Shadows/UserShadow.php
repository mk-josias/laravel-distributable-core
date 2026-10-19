<?php

declare(strict_types=1);

namespace Foundation\Iam\Shadows;

use Distributable\Attributes\Shadow;
use Distributable\Models\ShadowModel;

/** The shape of a copy of iam's users, declared once by iam for every module that keeps one. */
#[Shadow(module: 'iam', table: 'iam_users', migrations: [__DIR__.'/../database/shadows/users'])]
class UserShadow extends ShadowModel {}
