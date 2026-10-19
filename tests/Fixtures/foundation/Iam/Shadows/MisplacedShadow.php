<?php

declare(strict_types=1);

namespace Foundation\Iam\Shadows;

use Distributable\Attributes\Shadow;
use Distributable\Models\ShadowModel;

/** A copy naming a migrations folder that does not exist: modules:doctor reports it. */
#[Shadow(module: 'iam', table: 'iam_groups', migrations: [__DIR__.'/../database/shadows/groups'])]
final class MisplacedShadow extends ShadowModel {}
