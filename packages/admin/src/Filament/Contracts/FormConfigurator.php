<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Contracts;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Filament\Schemas\Schema;

interface FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema;
}
