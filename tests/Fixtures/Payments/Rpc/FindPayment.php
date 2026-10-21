<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Payments\Rpc;

use Distributable\Attributes\Rpc;
use Distributable\Contracts\Rpc\Message;

#[Rpc(module: 'payments')]
final readonly class FindPayment implements Message
{
    public function __construct(public int $id) {}

    public static function rules(): array
    {
        return ['id' => ['required', 'integer']];
    }
}
