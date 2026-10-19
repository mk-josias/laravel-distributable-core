<?php

declare(strict_types=1);

namespace Apps\Iam\Models;

use Apps\Iam\Database\Factories\UserFactory;
use Distributable\Attributes\ShadowFields;
use Distributable\Contracts\Shadows\Shadowed;
use Distributable\Traits\ShadowSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ShadowFields('name')]
final class User extends Model implements Shadowed
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use ShadowSource;

    protected $table = 'iam_users';

    protected $guarded = ['id'];
}
