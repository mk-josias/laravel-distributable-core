<?php

declare(strict_types=1);

namespace Foundation\Iam\Rpc;

use Distributable\Attributes\Rpc;
use Distributable\Contracts\Rpc\Message;

#[Rpc(module: 'iam')]
final readonly class FindUser implements Message
{
    public function __construct(public int $id) {}

    public static function rules(): array
    {
        return ['id' => ['required', 'integer']];
    }
}
