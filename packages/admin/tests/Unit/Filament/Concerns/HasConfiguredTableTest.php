<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Tests\Fixtures\Configurators\TableAwareConfigurator;
use Capell\Admin\Tests\Fixtures\Configurators\TestTableConfigurator;
use Filament\Tables\Table;

beforeEach(function (): void {
    TestTableConfigurator::$configurationCount = 0;
    TableAwareConfigurator::$tableConfigurationCount = 0;

    $manager = resolve(CapellAdminManager::class);
    $manager->contributeToAdminSurface(
        AdminSurfaceContributionData::configurator(
            TableAwareConfigurator::class,
            group: ConfiguratorTypeEnum::Page->value,
            name: 'TableAware',
        ),
    );

    CapellAdmin::swap($manager);
});

it('applies table-aware configurators after the base table configurator', function (): void {
    $resource = new class
    {
        use HasConfiguredTable;

        protected static string $tableConfigurator = TestTableConfigurator::class;
    };

    $table = Mockery::mock(Table::class);

    expect($resource::configuredTable($table, ConfiguratorTypeEnum::Page))
        ->toBe($table)
        ->and(TestTableConfigurator::$configurationCount)
        ->toBe(1)
        ->and(TableAwareConfigurator::$tableConfigurationCount)
        ->toBe(1);
});

it('only applies the base table configurator when no target is supplied', function (): void {
    $resource = new class
    {
        use HasConfiguredTable;

        protected static string $tableConfigurator = TestTableConfigurator::class;
    };

    $table = Mockery::mock(Table::class);

    expect($resource::configuredTable($table))
        ->toBe($table)
        ->and(TestTableConfigurator::$configurationCount)
        ->toBe(1)
        ->and(TableAwareConfigurator::$tableConfigurationCount)
        ->toBe(0);
});
