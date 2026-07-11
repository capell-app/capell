<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class PageForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        $resolver = resolve(ConfiguratorResolver::class);
        $record = $schema->getRecord();

        if ($record instanceof Model && $record->exists) {
            $configurator = $resolver->resolveForRecord($record, ConfiguratorTypeEnum::Page, DefaultPageConfigurator::getKey());

            return $configurator::configure($schema, ConfiguratorContextData::forEdit(
                ConfiguratorTypeEnum::Page,
                $context?->resourceName,
            ));
        }

        $type = filled($context?->typeKey)
            ? $resolver->resolveTypeByKey($context->typeKey, ConfiguratorTypeEnum::Page, $context->resourceName)
            : Page::getDefaultType($context?->resourceName);

        if (! $type instanceof Blueprint) {
            $type = $resolver->resolveDefaultType(ConfiguratorTypeEnum::Page, $context?->resourceName);
        }

        $context ??= ConfiguratorContextData::forCreate(
            ConfiguratorTypeEnum::Page,
            $type->key,
            PageResource::getResourceName(),
        );

        $configurator = $resolver->resolveForType($type, ConfiguratorTypeEnum::Page, DefaultPageConfigurator::getKey());

        return $configurator::configure($schema, $context);
    }
}
