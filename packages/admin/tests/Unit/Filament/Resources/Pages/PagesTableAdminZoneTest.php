<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminZoneContributionData;
use Capell\Admin\Enums\AdminZone;
use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Capell\Admin\Support\AdminZoneRegistry;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

beforeEach(function (): void {
    resolve(AdminZoneRegistry::class)->clear();
});

it('dogfoods the stable Page list table columns zone and keeps the legacy extender seam after it', function (): void {
    $registry = resolve(AdminZoneRegistry::class);
    $registry->register(new AdminZoneContributionData(
        zone: AdminZone::PageListTableColumns,
        key: 'capell-admin.pages.list.table.columns',
        resolver: static fn (): array => PagesTable::defaultTableColumns(),
        position: ExtensionPosition::first(),
    ));
    $registry->register(new AdminZoneContributionData(
        zone: AdminZone::PageListTableColumns,
        key: 'reference-extension.page-list.column',
        resolver: static fn (): array => [TextColumn::make('reference_extension_column')],
        position: ExtensionPosition::after('capell-admin.pages.list.table.columns'),
        owner: 'vendor/reference-extension',
    ));

    $method = new ReflectionMethod(PagesTable::class, 'getTableColumns');
    /** @var list<Column> $columns */
    $columns = $method->invoke(null);

    expect(array_map(static fn (Column $column): string => $column->getName(), $columns))
        ->toContain('reference_extension_column')
        ->toContain('id');
});
