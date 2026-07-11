<?php

declare(strict_types=1);

namespace Capell\Admin\Testing\Filament;

use ReflectionClass;

final class ReadsRawSchemaComponents
{
    /**
     * @return array<int, mixed>
     */
    public static function childComponents(object $component): array
    {
        $reflectionClass = new ReflectionClass($component);

        while (! $reflectionClass->hasProperty('childComponents')) {
            $parentClass = $reflectionClass->getParentClass();

            if (! $parentClass instanceof ReflectionClass) {
                return [];
            }

            $reflectionClass = $parentClass;
        }

        $property = $reflectionClass->getProperty('childComponents');

        $components = $property->getValue($component);

        return is_array($components) ? ($components['default'] ?? []) : [];
    }
}
