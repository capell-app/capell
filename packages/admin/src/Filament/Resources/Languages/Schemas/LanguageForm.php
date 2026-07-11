<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Languages\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Configurators\Languages\DefaultLanguageConfigurator;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Schemas\Schema;

class LanguageForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        $adminType = AdminSurfaceLookup::configurator(ConfiguratorTypeEnum::Language, DefaultLanguageConfigurator::getKey());

        return $adminType::configure($schema, $context)->columns();
    }
}
