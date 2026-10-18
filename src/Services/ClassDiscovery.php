<?php

declare(strict_types=1);

namespace Distributable\Services;

use ReflectionClass;
use Symfony\Component\Finder\Finder;

final class ClassDiscovery
{
    /** @var array<string, list<class-string>> */
    private static array $found = [];

    /**
     * The FQCN declared in a PHP file, read via the tokenizer (the file is NOT executed, so
     * route/config files have no side effects). Null when the file declares no named type
     * (routes, config arrays, anonymous-class migrations).
     */
    public static function declaredIn(string $file): ?string
    {
        $tokens = token_get_all((string) @file_get_contents($file));
        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = (string) ($tokens[$i + 2][1] ?? '');
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && ($tokens[$i + 1][0] ?? null) === T_WHITESPACE
                && ($tokens[$i + 2][0] ?? null) === T_STRING) {
                return $namespace !== '' ? $namespace.'\\'.$tokens[$i + 2][1] : $tokens[$i + 2][1];
            }
        }

        return null;
    }

    /**
     * The classes under a directory carrying a given trait/interface/attribute/parent, by scanning
     * the source tree (so classes not yet autoloaded are found, unlike get_declared_classes).
     *
     * @return list<class-string>
     */
    public static function with(string $target, string $directory): array
    {
        $key = $target.'|'.$directory;

        if (isset(self::$found[$key])) {
            return self::$found[$key];
        }

        if (! is_dir($directory)) {
            return self::$found[$key] = [];
        }

        $found = [];

        foreach (Finder::create()->files()->name('*.php')->in($directory) as $file) {
            $class = self::declaredIn($file->getRealPath());
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);
            $matches = $ref->isSubclassOf($target)
                || in_array($target, class_implements($class) ?: [], true)
                || in_array($target, class_uses_recursive($class), true)
                || $ref->getAttributes($target) !== [];

            if ($matches) {
                $found[] = $class;
            }
        }

        return self::$found[$key] = $found;
    }
}
