<?php

declare(strict_types=1);

namespace Distributable\Attributes;

use Attribute;

/** The fields of a source model that travel to the copies; any other never leaves its module. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ShadowFields
{
    /** @var list<string> */
    public array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }
}
