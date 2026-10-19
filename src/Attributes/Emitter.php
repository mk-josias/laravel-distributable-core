<?php

declare(strict_types=1);

namespace Distributable\Attributes;

use Attribute;

/** The module an event is announced for, on an event class that lives outside it, in the foundation. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Emitter
{
    public function __construct(public string $module) {}
}
