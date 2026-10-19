<?php

declare(strict_types=1);

namespace Distributable\Contracts\Rpc;

/** Carries a message to the module that answers it. */
interface Transport
{
    /**
     * @param  array<string, mixed>  $payload  the message's properties, by name
     * @return mixed the answer decoded as JSON would decode it; null when there is none
     */
    public function invoke(string $module, string $name, int $version, array $payload): mixed;
}
