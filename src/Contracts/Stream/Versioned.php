<?php

declare(strict_types=1);

namespace Distributable\Contracts\Stream;

/** A payload whose shape has changed: how an older payload is lifted one version up. Its version is the one #[Payload] declares. */
interface Versioned
{
    /**
     * @param  array<string, mixed>  $payload  as written in version $from
     * @return array<string, mixed> the same payload in version $from + 1
     */
    public static function upcast(int $from, array $payload): array;
}
