<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminZoneContributionData;
use Capell\Admin\Enums\AdminZone;
use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\AdminZoneRegistry;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Arr;

beforeEach(function (): void {
    resolve(AdminZoneRegistry::class)->clear();
    app()->forgetInstance(AdminRuntimeActivator::class);
});

it('dogfoods the stable Page list table columns zone and keeps the legacy extender seam after it', function (): void {
    $registry = resolve(AdminZoneRegistry::class);
    resolve(AdminRuntimeActivator::class)->prepare();

    $builtIn = $registry->contributions(AdminZone::PageListTableColumns)[0];

    expect($builtIn->key())
        ->toBe('capell-admin.pages.list.table.columns')
        ->and($builtIn->owner())->toBe('capell-app/admin')
        ->and($builtIn->source())->toBe(AdminServiceProvider::class);

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

    $names = array_map(static fn (Column $column): string => $column->getName(), $columns);

    expect($names)
        ->toContain('reference_extension_column')
        ->toContain('id')
        ->and($names[0])->toBe('id')
        ->and(Arr::last($names))->toBe('reference_extension_column');
});
