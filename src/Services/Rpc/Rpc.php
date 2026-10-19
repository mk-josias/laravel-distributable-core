<?php

declare(strict_types=1);

namespace Distributable\Services\Rpc;

use Distributable\Contracts\Rpc\Message;
use Distributable\Contracts\Rpc\Transport;

/** Sends a message to the module that answers it: in this process when it runs here, over its transport otherwise. */
final readonly class Rpc
{
    public function __construct(private Transport $transport) {}

    public function send(Message $message): mixed
    {
        ['module' => $module, 'name' => $name, 'version' => $version] = MessageRegistry::describe($message::class);

        return $this->transport->invoke($module, $name, $version, MessageRegistry::payloadOf($message));
    }
}
