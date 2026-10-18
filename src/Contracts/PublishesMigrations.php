<?php

declare(strict_types=1);

namespace Distributable\Contracts;

/** A copy whose owner ships the migrations of its table: every module that keeps it runs them in its own database. */
interface PublishesMigrations
{
    /**
     * Files or folders, as Laravel's loadMigrationsFrom(): a folder brings every migration in it, whatever its name.
     *
     * @return list<string>
     */
    public static function migrations(): array;
}
