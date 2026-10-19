<?php

declare(strict_types=1);

namespace Distributable\Attributes;

use Attribute;

/** What a message says of itself: the module that answers it, its version, and its name on the wire (the class name in kebab case by default). */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Rpc
{
    public function __construct(public string $module, public int $version = 1, public ?string $name = null) {}
}
