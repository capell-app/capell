<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Themes\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Configurators\Themes\FoundationThemeConfigurator;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class ThemeForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        $resolver = resolve(ConfiguratorResolver::class);
        $record = $schema->getRecord();

        if ($record instanceof Model && $record->exists) {
            $configurator = $resolver->resolveForRecord($record, ConfiguratorTypeEnum::Theme, FoundationThemeConfigurator::getKey());

            return $configurator::configure($schema, ConfiguratorContextData::forEdit(
                ConfiguratorTypeEnum::Theme,
                $context?->resourceName,
            ))->columns();
        }

        $type = filled($context?->typeKey)
            ? $resolver->resolveTypeByKey($context->typeKey, ConfiguratorTypeEnum::Theme, $context->resourceName)
            : $resolver->resolveDefaultType(ConfiguratorTypeEnum::Theme, $context?->resourceName);

        $configurator = $resolver->resolveForType($type, ConfiguratorTypeEnum::Theme, FoundationThemeConfigurator::getKey());

        return $configurator::configure($schema, $context)->columns();
    }
}
