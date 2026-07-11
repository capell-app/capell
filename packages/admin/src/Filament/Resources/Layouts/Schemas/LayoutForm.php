<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Layouts\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Configurators\Layouts\DefaultLayoutConfigurator;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Filament\Schemas\Schema;

class LayoutForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        $context ??= ConfiguratorContextData::forEdit(ConfiguratorTypeEnum::Layout);

        return DefaultLayoutConfigurator::configure($schema, $context)->columns();
    }
}
