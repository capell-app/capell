<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts;

use BackedEnum;

/**
 * @mixin BackedEnum
 */
interface ConfiguratorTypeEnumInterface
{
    /**
     * @return array<string, list<class-string<ConfiguratorInterface>>>
     */
    public static function getAllConfigurators(): array;

    public static function fromName(string $name): ?static;

    public function getName(): string;

    /**
     * @return array<class-string<ConfiguratorInterface>>
     */
    public function getConfigurators(): array;
}
