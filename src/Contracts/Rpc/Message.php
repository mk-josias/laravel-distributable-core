<?php

declare(strict_types=1);

namespace Distributable\Contracts\Rpc;

/** A call to a module: its properties are the payload, and its rules validate it on both sides. */
interface Message
{
    /** @return array<string, mixed> */
    public static function rules(): array;
}
