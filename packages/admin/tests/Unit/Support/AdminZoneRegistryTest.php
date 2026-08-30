<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminZoneContextData;
use Capell\Admin\Data\AdminZoneContributionData;
use Capell\Admin\Enums\AdminZone;
use Capell\Admin\Support\AdminZoneRegistry;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Gate;

function adminZoneContext(): AdminZoneContextData
{
    return new AdminZoneContextData(AdminZone::PageListTableColumns, 'tests.page-list', auth()->user());
}

function adminZoneContribution(
    string $key,
    Closure $resolver,
    ?ExtensionPosition $position = null,
    ?string $permission = null,
    ?Closure $visibility = null,
    string $owner = 'tests/admin',
): AdminZoneContributionData {
    return new AdminZoneContributionData(
        zone: AdminZone::PageListTableColumns,
        key: $key,
        resolver: $resolver,
        position: $position,
        permission: $permission,
        visibility: $visibility,
        owner: $owner,
        source: 'AdminZoneRegistryTest',
    );
}

it('interleaves built-in and package columns through the shared ordering resolver', function (): void {
    $registry = new AdminZoneRegistry;

    $builtIn = adminZoneContribution(
        'core.page-list.columns',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('core_column')],
        ExtensionPosition::first(),
        owner: 'capell-app/admin',
    );
    $packageBefore = adminZoneContribution(
        'package.before',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('package_before')],
        ExtensionPosition::before('core.page-list.columns'),
        owner: 'vendor/reference-extension',
    );
    $packageAfter = adminZoneContribution(
        'package.after',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('package_after')],
        ExtensionPosition::after('core.page-list.columns'),
        owner: 'vendor/reference-extension',
    );

    $registry->register($builtIn);
    $registry->register($packageAfter);
    $registry->register($packageBefore);

    $columns = $registry->resolve(AdminZone::PageListTableColumns, adminZoneContext());

    expect(array_map(static fn (TextColumn $column): string => $column->getName(), $columns))
        ->toBe(['package_before', 'core_column', 'package_after']);
});

it('exposes missing-anchor diagnostics without changing deterministic fallback order', function (): void {
    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'package.column',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('package_column')],
        ExtensionPosition::after('missing.anchor'),
    ));

    $columns = $registry->resolve(AdminZone::PageListTableColumns, adminZoneContext());

    expect($columns[0]->getName())->toBe('package_column')
        ->and($registry->orderingDiagnostics(AdminZone::PageListTableColumns)[0]->type)->toBe('missing-anchor');
});

it('enforces idempotence, explicit replacement, duplicate ownership diagnostics, and freeze', function (): void {
    $registry = new AdminZoneRegistry;
    $original = adminZoneContribution(
        'same.key',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('original')],
    );

    $registry->register($original);
    $registry->register($original);

    expect($registry->contributions(AdminZone::PageListTableColumns))->toHaveCount(1);

    expect(function () use ($registry): void {
        $registry->register(adminZoneContribution(
            'same.key',
            static fn (AdminZoneContextData $context): array => [TextColumn::make('conflict')],
            owner: 'vendor/other',
        ));
    })->toThrow(LogicException::class, 'vendor/other');

    $replacement = adminZoneContribution(
        'same.key',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('replacement')],
        owner: 'vendor/other',
    );
    $registry->replace($replacement);
    expect($registry->resolve(AdminZone::PageListTableColumns, adminZoneContext())[0]->getName())->toBe('replacement');

    $registry->freeze();
    expect(function () use ($registry): void {
        $registry->register(adminZoneContribution(
            'late.key',
            static fn (AdminZoneContextData $context): array => [TextColumn::make('late')],
            owner: 'vendor/late',
        ));
    })->toThrow(LogicException::class, 'vendor/late');
});

it('filters contributions by declared permission and visibility', function (): void {
    test()->actingAsAdmin();

    Gate::define('tests.view-admin-zone', static fn (): bool => true);

    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'permitted',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('permitted')],
        permission: 'tests.view-admin-zone',
    ));
    $registry->register(adminZoneContribution(
        'hidden',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('hidden')],
        visibility: static fn (AdminZoneContextData $context): bool => false,
    ));

    $columns = $registry->resolve(AdminZone::PageListTableColumns, adminZoneContext());

    expect($columns)->toHaveCount(1)
        ->and($columns[0]->getName())->toBe('permitted');
});

it('rejects values outside the zone contract', function (): void {
    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'invalid',
        static fn (AdminZoneContextData $context): array => ['not a column'],
    ));

    expect(fn (): array => $registry->resolve(AdminZone::PageListTableColumns, adminZoneContext()))
        ->toThrow(LogicException::class, 'returned [string]');
});
