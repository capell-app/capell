<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Filament\Schemas\Schema;
use LogicException;

/**
 * @property class-string<FormConfigurator> $formConfigurator
 */
trait InteractsWithConfiguredForm
{
    /** @return class-string<FormConfigurator> */
    public static function getFormConfigurator(): string
    {
        $configurator = static::$formConfigurator;

        if (! is_subclass_of($configurator, FormConfigurator::class)) {
            throw new LogicException(sprintf('Form configurator [%s] must implement %s.', $configurator, FormConfigurator::class));
        }

        return $configurator;
    }

    public static function configuredForm(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return static::getFormConfigurator()::configure($schema, $context);
    }
}
