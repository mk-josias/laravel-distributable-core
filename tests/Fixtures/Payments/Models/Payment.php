<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Models;

use Distributable\Attributes\ShadowFields;
use Distributable\Contracts\Shadows\Shadowed;
use Distributable\Traits\ShadowSource;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $amount
 * @property string $note
 */
#[ShadowFields('amount')]
final class Payment extends Model implements Shadowed
{
    use ShadowSource;

    public $timestamps = false;

    protected $guarded = [];
}
