<?php

declare(strict_types=1);

namespace Apps\Analytics\Models;

use Foundation\Iam\Shadows\UserShadow as IamUserShadow;

/** Analytics' own form of iam's users: a subclass a keeper declares to add relations or a beforeSync(). */
final class UserShadow extends IamUserShadow {}
