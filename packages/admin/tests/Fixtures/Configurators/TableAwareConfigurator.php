<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Configurators;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\Configurators\ConfiguresTable;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TableAwareConfigurator implements ConfiguratorInterface, ConfiguresTable
{
    public static int $tableConfigurationCount = 0;

    public static function getKey(): string
    {
        return 'TableAware';
    }

    public static function getSort(): int
    {
        return 10;
    }

    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema;
    }

    public static function configureTable(Table $table): Table
    {
        self::$tableConfigurationCount++;

        return $table;
    }
}
