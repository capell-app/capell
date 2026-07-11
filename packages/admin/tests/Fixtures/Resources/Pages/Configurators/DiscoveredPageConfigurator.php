<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Resources\Pages\Configurators;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Filament\Schemas\Schema;

class DiscoveredPageConfigurator implements ConfiguratorInterface
{
    public static function getKey(): string
    {
        return 'Discovered';
    }

    public static function getSort(): int
    {
        return 5;
    }

    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema;
    }
}
