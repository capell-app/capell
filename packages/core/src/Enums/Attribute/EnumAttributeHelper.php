<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Attribute;

use ReflectionEnum;

trait EnumAttributeHelper
{
    /**
     * Get all attribute instances of the given class from all enum cases.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attributeClass
     * @return array<string, T|null>
     */
    public static function getAllCaseAttributes(string $attributeClass): array
    {
        $attributes = [];
        $reflection = new ReflectionEnum(static::class);
        foreach (static::cases() as $case) {
            $refCase = $reflection->getCase($case->name);
            $attribute = $refCase->getAttributes($attributeClass)[0] ?? null;
            $attributes[$case->value] = $attribute?->newInstance();
        }

        return $attributes;
    }

    /**
     * Get the first attribute instance of the given class from the current enum case.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attributeClass
     * @return T|null
     */
    public function getCaseAttribute(string $attributeClass): ?object
    {
        $reflection = new ReflectionEnum(static::class);
        $case = $reflection->getCase($this->name);
        $attribute = $case->getAttributes($attributeClass)[0] ?? null;

        return $attribute?->newInstance();
    }
}
