<?php

declare(strict_types=1);

namespace Distributable\Attributes;

use Attribute;
use BackedEnum;

/** What an event says of itself: its name on the stream (a string, or the case of an enum that holds the names), the module that emits it (the module its class belongs to by default) and its stream (the default one by default). */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Emits
{
    public string $name;

    public function __construct(BackedEnum|string $name, public ?string $module = null, public ?string $stream = null)
    {
        $this->name = $name instanceof BackedEnum ? (string) $name->value : $name;
    }
}
