<?php

declare(strict_types=1);

namespace Distributable\Tests\Fixtures\Messaging\Services;

use Distributable\Services\Rpc\RpcClient;
use Distributable\Tests\Fixtures\Messaging\Rpc\ConversationFor;

final class MessagingClient extends RpcClient
{
    public function conversationFor(int $paymentId): mixed
    {
        return $this->send(new ConversationFor($paymentId));
    }

    /**
     * @param  list<string>|null  $tags
     * @return array<string, mixed>|null
     */
    public function cachedConversationFor(int $paymentId, ?array $tags = null): ?array
    {
        return $this->readThrough("messaging:conversation:{$paymentId}", self::DEFAULT_TTL, fn (): mixed => $this->send(new ConversationFor($paymentId)), fn (array $raw): array => $raw, $tags);
    }

    /** @param list<string>|null $tags */
    public function forgetConversation(int $paymentId, ?array $tags = null): void
    {
        $this->forget("messaging:conversation:{$paymentId}", $tags);
    }
}
