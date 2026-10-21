<?php

declare(strict_types=1);

namespace Distributable\Attributes;

use Attribute;
use BackedEnum;

/** What a payload class says of itself: the event it carries (a name, or the case of an enum that holds the names) and the version of its shape, which is raised when a field is renamed, removed or changes meaning. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Payload
{
    public string $event;

    public function __construct(BackedEnum|string $event, public int $version = 1)
    {
        $this->event = $event instanceof BackedEnum ? (string) $event->value : $event;
    }
}
