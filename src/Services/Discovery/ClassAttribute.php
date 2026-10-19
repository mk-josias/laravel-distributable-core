<?php

declare(strict_types=1);

namespace Distributable\Services\Discovery;

use ReflectionClass;

/** The attribute a class carries, or its nearest parent does: reflection doesn't inherit them. */
final class ClassAttribute
{
    /** @var array<string, object|null> */
    private static array $found = [];

    /**
     * @template T of object
     *
     * @param  class-string  $class
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    public static function of(string $class, string $attribute): ?object
    {
        /** @var T|null */
        return self::$found["{$class}|{$attribute}"] ??= self::read($class, $attribute);
    }

    private static function read(string $class, string $attribute): ?object
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $declared = $reflection->getAttributes($attribute)[0] ?? null;

            if ($declared !== null) {
                return $declared->newInstance();
            }
        }

        return null;
    }
}
