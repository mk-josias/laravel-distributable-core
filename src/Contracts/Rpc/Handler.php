<?php

declare(strict_types=1);

namespace Distributable\Contracts\Rpc;

/**
 * A module's answer to one message, declared in its provider's $rpcHandlers. PHP cannot narrow a parameter
 * in an interface, so `handle(FindUser $message)` is not declared here: MessageRegistry checks it
 * when the provider registers.
 *
 * @template TMessage of Message
 */
interface Handler {}
