<?php

declare(strict_types=1);

namespace Distributable\Attributes;

use Attribute;

/** What a copy says of itself: the module that owns the source table, that table, and the migrations the owner publishes for the copy (files or folders, as loadMigrationsFrom()). */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Shadow
{
    /** @param list<string> $migrations */
    public function __construct(public string $module, public string $table, public array $migrations) {}
}
