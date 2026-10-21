<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Models;

use Distributable\Attributes\ShadowFields;
use Distributable\Attributes\ShadowStream;
use Distributable\Contracts\Shadows\Shadowed;
use Distributable\Traits\ShadowSource;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $amount
 */
#[ShadowFields('amount')]
#[ShadowStream('shadows')]
final class Refund extends Model implements Shadowed
{
    use ShadowSource;

    public $timestamps = false;

    protected $guarded = [];
}
