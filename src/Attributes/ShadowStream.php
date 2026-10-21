<?php

declare(strict_types=1);

namespace Distributable\Attributes;

use Attribute;

/** The stream of distributable.events.streams a source model's rows are announced on; the default one without it. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ShadowStream
{
    public function __construct(public string $name) {}
}
