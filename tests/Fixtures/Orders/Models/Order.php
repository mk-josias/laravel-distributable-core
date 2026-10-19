<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Models;

use Distributable\Attributes\ShadowFields;
use Distributable\Contracts\Shadows\Shadowed;
use Distributable\Traits\ShadowSource;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $total
 * @property string $note
 */
#[ShadowFields('total')]
final class Order extends Model implements Shadowed
{
    use ShadowSource;

    public $timestamps = false;

    protected $guarded = [];
}
