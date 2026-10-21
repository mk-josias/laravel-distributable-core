<?php

declare(strict_types=1);

namespace Distributable\Context;

/** The settings a module keeps for itself, one value per path; a list is one value, not one per item. */
final class ModuleConfig
{
    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    public static function leaves(array $values, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $leaves = [...$leaves, ...self::leaves($value, $path)];

                continue;
            }

            $leaves[$path] = $value;
        }

        return $leaves;
    }
}
