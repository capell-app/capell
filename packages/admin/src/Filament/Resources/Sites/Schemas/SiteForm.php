<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Configurators\Sites\DefaultSiteConfigurator;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class SiteForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        $resolver = resolve(ConfiguratorResolver::class);
        $record = $schema->getRecord();

        if ($record instanceof Model && $record->exists) {
            $configurator = $resolver->resolveForRecord($record, ConfiguratorTypeEnum::Site, DefaultSiteConfigurator::getKey());

            return $configurator::configure($schema, ConfiguratorContextData::forEdit(
                ConfiguratorTypeEnum::Site,
                $context?->resourceName,
            ));
        }

        $type = filled($context?->typeKey)
            ? $resolver->resolveTypeByKey($context->typeKey, ConfiguratorTypeEnum::Site, $context->resourceName)
            : $resolver->resolveDefaultType(ConfiguratorTypeEnum::Site, $context?->resourceName);

        $configurator = $resolver->resolveForType($type, ConfiguratorTypeEnum::Site, DefaultSiteConfigurator::getKey());

        return $configurator::configure($schema, $context);
    }
}
