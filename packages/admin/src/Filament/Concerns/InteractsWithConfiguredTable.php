<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Contracts\Configurators\ConfiguresTable;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Tables\Table;
use LogicException;

/**
 * @property class-string<TableConfigurator> $tableConfigurator
 */
trait InteractsWithConfiguredTable
{
    /** @return class-string<TableConfigurator> */
    public static function getTableConfigurator(): string
    {
        $configurator = static::$tableConfigurator;

        if (! is_subclass_of($configurator, TableConfigurator::class)) {
            throw new LogicException(sprintf('Table configurator [%s] must implement %s.', $configurator, TableConfigurator::class));
        }

        return $configurator;
    }

    public static function configuredTable(Table $table, ?ConfiguratorTypeEnumInterface $target = null): Table
    {
        $table = static::getTableConfigurator()::configure($table);

        if (! $target instanceof ConfiguratorTypeEnumInterface) {
            return $table;
        }

        foreach (AdminSurfaceLookup::configurators($target) as $configurator) {
            if (is_a($configurator, ConfiguresTable::class, true)) {
                $table = $configurator::configureTable($table);
            }
        }

        return $table;
    }
}
