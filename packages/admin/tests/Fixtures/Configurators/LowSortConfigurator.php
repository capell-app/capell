<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Configurators;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Filament\Schemas\Schema;

class LowSortConfigurator implements ConfiguratorInterface
{
    public static function getKey(): string
    {
        return 'Low';
    }

    public static function getSort(): int
    {
        return 10;
    }

    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema;
    }
}
