<?php

declare(strict_types=1);

namespace Capell\Admin\Exceptions;

use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use RuntimeException;

final class ConfiguratorTypeNotFoundException extends RuntimeException
{
    public static function forKey(string $key, ConfiguratorTypeEnumInterface $target, ?string $resourceName = null): self
    {
        return new self(sprintf(
            'Configurator type `%s` was not found for %s%s.',
            $key,
            $target->getName(),
            $resourceName !== null ? sprintf(' on resource `%s`', $resourceName) : '',
        ));
    }

    public static function forDefault(ConfiguratorTypeEnumInterface $target, ?string $resourceName = null): self
    {
        return new self(sprintf(
            'Default configurator type was not found for %s%s.',
            $target->getName(),
            $resourceName !== null ? sprintf(' on resource `%s`', $resourceName) : '',
        ));
    }
}
