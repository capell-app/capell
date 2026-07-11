<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Schemas;

use Filament\Schemas\Components\Component;

final class SchemaPositioner
{
    /**
     * @param  array<int, Component>  $components
     * @return array<int, Component>
     */
    public static function append(array $components, Component $contribution): array
    {
        $components[] = $contribution;

        return $components;
    }

    /**
     * @param  array<int, Component>  $components
     * @return array<int, Component>
     */
    public static function prepend(array $components, Component $contribution): array
    {
        array_unshift($components, $contribution);

        return $components;
    }

    /**
     * @param  array<int, Component>  $components
     * @return array<int, Component>
     */
    public static function insertAfter(array $components, Component $contribution, string $key): array
    {
        return self::insertAtKey($components, $contribution, $key, after: true);
    }

    /**
     * @param  array<int, Component>  $components
     * @return array<int, Component>
     */
    public static function insertBefore(array $components, Component $contribution, string $key): array
    {
        return self::insertAtKey($components, $contribution, $key, after: false);
    }

    /**
     * @param  array<int, Component>  $components
     * @return array<int, Component>
     */
    private static function insertAtKey(array $components, Component $contribution, string $key, bool $after): array
    {
        $index = self::findKeyIndex($components, $key);

        if ($index === null) {
            return self::append($components, $contribution);
        }

        $insertIndex = $after ? $index + 1 : $index;
        array_splice($components, $insertIndex, 0, [$contribution]);

        return $components;
    }

    /**
     * @param  array<int, Component>  $components
     */
    private static function findKeyIndex(array $components, string $key): ?int
    {
        foreach ($components as $index => $component) {
            if ($component->getKey(isAbsolute: false) === $key) {
                return $index;
            }
        }

        return null;
    }
}
