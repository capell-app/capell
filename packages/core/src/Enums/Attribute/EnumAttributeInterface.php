<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Attribute;

/**
 * @template T of object
 */
interface EnumAttributeInterface
{
    /**
     * Get all attribute instances of the given class from all enum cases.
     *
     * @param  class-string<T>  $attributeClass
     * @return array<string, T|null>
     */
    public static function getAllCaseAttributes(string $attributeClass): array;

    /**
     * Get the first attribute instance of the given class from the current enum case.
     *
     * @param  class-string<T>  $attributeClass
     * @return T|null
     */
    public function getCaseAttribute(string $attributeClass): ?object;
}
