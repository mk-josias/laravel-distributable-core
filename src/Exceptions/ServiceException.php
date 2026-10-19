<?php

declare(strict_types=1);

namespace Distributable\Exceptions;

use RuntimeException;

/** A service that cannot be resolved, or a call or an event it cannot take. */
final class ServiceException extends RuntimeException
{
    public static function outsideService(string $class): self
    {
        return new self("Cannot resolve the service of [{$class}]: it belongs to no service this process knows.");
    }

    public static function unknownRpcMessage(string $module, string $name, int $version): self
    {
        return new self("Module [{$module}] answers no [{$name}] in version {$version}: only a message it registers in \$rpc can be sent.");
    }

    public static function eventVersionAhead(string $name, int $version, int $known): self
    {
        return new self("Event [{$name}] arrived in version {$version}, and this process reads it up to version {$known}: deploy its consumer before it is handled.");
    }
}
