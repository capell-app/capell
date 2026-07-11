<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Stubs;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Filament\Schemas\Schema;

class ExampleForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema;
    }
}
