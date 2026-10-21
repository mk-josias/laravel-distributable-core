<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Messaging\Rpc;

use Distributable\Attributes\Rpc;
use Distributable\Contracts\Rpc\Message;

#[Rpc(module: 'messaging')]
final readonly class ConversationFor implements Message
{
    public function __construct(public int $paymentId) {}

    public static function rules(): array
    {
        return ['paymentId' => ['required', 'integer']];
    }
}
