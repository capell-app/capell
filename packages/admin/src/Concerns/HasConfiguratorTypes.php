<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use UnitEnum;

/**
 * @mixin UnitEnum
 */
trait HasConfiguratorTypes
{
    /**
     * @return array<string, list<class-string<ConfiguratorInterface>>>
     */
    public static function getAllConfigurators(): array
    {
        return collect(static::cases())
            ->mapWithKeys(fn (ConfiguratorTypeEnumInterface $enum): array => [$enum->value => array_values($enum->getConfigurators())])
            ->all();
    }

    public static function fromName(string $name): ?static
    {
        foreach (static::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
