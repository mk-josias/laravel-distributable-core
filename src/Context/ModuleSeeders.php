<?php

declare(strict_types=1);

namespace Distributable\Context;

use Distributable\Data\Module;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Seeder;

/**
 * The seeders Laravel's db:seed asks the container for, as Database\Seeders\{Name}: in a module, the module's own wins, and
 * DatabaseSeeder runs the application's, then the module's, as migrate runs the application's migrations, then the module's.
 */
final class ModuleSeeders
{
    private const string NAMESPACE = 'Database\\Seeders\\';

    private const string ROOT = 'DatabaseSeeder';

    public static function bind(Application $app, Module $module): void
    {
        foreach (glob($module->path().'/database/seeders/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $resolve = static fn (Application $app): Seeder => self::resolve($app, $name);

            $app->bind(self::NAMESPACE.$name, $resolve);

            // db:seed asks for DatabaseSeeder, without namespace, when the application has none.
            if ($name === self::ROOT) {
                $app->bind(self::ROOT, $resolve);
            }
        }
    }

    private static function resolve(Application $app, string $name): Seeder
    {
        $module = $app->make(ModuleContext::class)->current();
        $own = $module !== null && class_exists($class = $module->namespace.'\\'.self::NAMESPACE.$name) ? $class : null;
        $application = class_exists(self::NAMESPACE.$name) ? self::NAMESPACE.$name : null;

        if ($name === self::ROOT) {
            return new DatabaseSeeders(array_values(array_filter([$application, $own], is_string(...))));
        }

        /** @var class-string<Seeder> $class */
        $class = $own ?? $application ?? self::NAMESPACE.$name;

        return $app->build($class);
    }
}
