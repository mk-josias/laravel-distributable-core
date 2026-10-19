<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Orders\Rpc;

use Distributable\Attributes\Rpc;
use Distributable\Contracts\Rpc\Message;

#[Rpc(module: 'orders')]
final readonly class FindOrder implements Message
{
    public function __construct(public int $id) {}

    public static function rules(): array
    {
        return ['id' => ['required', 'integer']];
    }
}
