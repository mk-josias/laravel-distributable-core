<?php

declare(strict_types=1);

namespace Distributable\Context;

use Illuminate\Database\Seeder;

/** The DatabaseSeeder of a module: the application's, then the module's own, each built as written, never through its binding. */
final class DatabaseSeeders extends Seeder
{
    /** @param list<class-string<Seeder>> $seeders */
    public function __construct(private readonly array $seeders) {}

    public function run(): void
    {
        foreach ($this->seeders as $class) {
            $seeder = $this->container->build($class)->setContainer($this->container);

            if ($this->command !== null) {
                $seeder->setCommand($this->command);
                $this->command->getOutput()->writeln("  <comment>Seeding:</comment> {$class}");
            }

            $seeder->__invoke();
        }
    }
}
