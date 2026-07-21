<?php

declare(strict_types=1);

use Capell\Admin\Enums\NavigationGroupPositionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\CapellAdminManager;
use Filament\Navigation\NavigationGroup;
use Filament\Support\Icons\Heroicon;

beforeEach(function (): void {
    CapellAdmin::clearResolvedInstance(CapellAdminManager::class);
});

it('keeps the frequent website group open and collapses secondary groups', function (): void {
    $navigationGroups = CapellAdmin::getDefaultNavigationGroups();

    foreach ($navigationGroups as $navigationGroup) {
        expect($navigationGroup)
            ->toBeInstanceOf(NavigationGroup::class)
            ->and($navigationGroup->getIcon())->toBeNull();
    }

    $navigationGroups = collect($navigationGroups);
    $websiteGroup = $navigationGroups->firstOrFail(
        fn (NavigationGroup $navigationGroup): bool => $navigationGroup->getLabel() === __('capell-admin::navigation.group_websites'),
    );
    $settingsGroup = $navigationGroups->firstOrFail(
        fn (NavigationGroup $navigationGroup): bool => $navigationGroup->getLabel() === __('capell-admin::navigation.group_settings'),
    );
    $systemGroup = $navigationGroups->firstOrFail(
        fn (NavigationGroup $navigationGroup): bool => $navigationGroup->getLabel() === __('capell-admin::navigation.group_system'),
    );

    expect($websiteGroup->isCollapsed())->toBeFalse()
        ->and($settingsGroup->isCollapsed())->toBeFalse()
        ->and($systemGroup->isCollapsed())->toBeTrue();
});

it('registers navigation groups, merges duplicates, and applies relative ordering', function (): void {
    CapellAdmin::registerNavigationGroup(
        label: 'Publishing',
        icon: Heroicon::OutlinedDocumentText,
    );

    CapellAdmin::registerNavigationGroup(
        label: 'Publishing',
        icon: Heroicon::OutlinedNewspaper,
        position: NavigationGroupPositionEnum::After,
        relativeTo: 'capell-admin::navigation.group_marketing',
    );

    $navigationGroups = CapellAdmin::getNavigationGroups();
    $labels = array_map(
        fn (NavigationGroup $navigationGroup): ?string => $navigationGroup->getLabel(),
        $navigationGroups,
    );

    expect(array_values(array_filter($labels, fn (?string $label): bool => $label === 'Publishing')))
        ->toHaveCount(1)
        ->and(array_search('Publishing', $labels, true))
        ->toBe(array_search(__('capell-admin::navigation.group_marketing'), $labels, true) + 1);

    $publishingGroup = $navigationGroups[array_search('Publishing', $labels, true)];

    expect($publishingGroup->getIcon())->toBe(Heroicon::OutlinedNewspaper);
});

it('orders package navigation groups while keeping the system group last', function (): void {
    CapellAdmin::registerNavigationGroup(
        label: 'capell-admin::navigation.group_marketing',
        icon: Heroicon::OutlinedMegaphone,
        position: NavigationGroupPositionEnum::Before,
        relativeTo: 'capell-admin::navigation.group_system',
    );

    CapellAdmin::registerNavigationGroup(
        label: 'capell-admin::navigation.group_marketing',
        icon: Heroicon::OutlinedEnvelope,
        position: NavigationGroupPositionEnum::Before,
        relativeTo: 'capell-admin::navigation.group_system',
    );

    $navigationGroups = CapellAdmin::getNavigationGroups();
    $labels = array_map(
        fn (NavigationGroup $navigationGroup): ?string => $navigationGroup->getLabel(),
        $navigationGroups,
    );
    $marketingPosition = array_search(__('capell-admin::navigation.group_marketing'), $labels, true);
    $systemPosition = array_search(__('capell-admin::navigation.group_system'), $labels, true);

    assert(is_int($marketingPosition));
    assert(is_int($systemPosition));

    expect($marketingPosition)
        ->toBeLessThan($systemPosition)
        ->and($navigationGroups[$marketingPosition]->getIcon())->toBe(Heroicon::OutlinedEnvelope)
        ->and(array_pop($labels))->toBe(__('capell-admin::navigation.group_system'));
});

it('merges package navigation group metadata into host supplied groups', function (): void {
    CapellAdmin::setNavigationGroups([
        NavigationGroup::make()
            ->label(__('capell-admin::navigation.group_system')),
        NavigationGroup::make()
            ->label('Host only'),
    ]);

    CapellAdmin::registerNavigationGroup(
        label: 'capell-admin::navigation.group_system',
        icon: Heroicon::OutlinedGlobeAlt,
        collapsed: false,
    );

    CapellAdmin::registerNavigationGroup(
        label: 'Package reports',
        icon: Heroicon::OutlinedChartBar,
        collapsed: false,
    );

    $navigationGroups = CapellAdmin::getNavigationGroups();
    $labels = array_map(
        fn (NavigationGroup $navigationGroup): ?string => $navigationGroup->getLabel(),
        $navigationGroups,
    );

    $systemGroup = $navigationGroups[array_search(__('capell-admin::navigation.group_system'), $labels, true)];
    $reportsGroup = $navigationGroups[array_search('Package reports', $labels, true)];

    expect($labels)->toBe([
        __('capell-admin::navigation.group_system'),
        'Host only',
        'Package reports',
    ])
        ->and($systemGroup->getIcon())->toBe(Heroicon::OutlinedGlobeAlt)
        ->and($systemGroup->isCollapsed())->toBeFalse()
        ->and($reportsGroup->getIcon())->toBe(Heroicon::OutlinedChartBar)
        ->and($reportsGroup->isCollapsed())->toBeFalse();
});

it('keeps relative package groups when their anchor is not present', function (): void {
    CapellAdmin::registerNavigationGroup(
        label: 'Before missing',
        position: NavigationGroupPositionEnum::Before,
        relativeTo: 'Missing anchor',
    );

    CapellAdmin::registerNavigationGroup(
        label: 'After missing',
        position: NavigationGroupPositionEnum::After,
        relativeTo: 'Missing anchor',
    );

    $labels = array_map(
        fn (NavigationGroup $navigationGroup): ?string => $navigationGroup->getLabel(),
        CapellAdmin::getNavigationGroups(),
    );
    $beforeMissingPosition = array_search('Before missing', $labels, true);
    $afterMissingPosition = array_search('After missing', $labels, true);
    $systemPosition = array_search(__('capell-admin::navigation.group_system'), $labels, true);

    assert(is_int($beforeMissingPosition));
    assert(is_int($afterMissingPosition));
    assert(is_int($systemPosition));

    expect($labels)->toContain('Before missing', 'After missing')
        ->and($beforeMissingPosition)->toBeLessThan($systemPosition)
        ->and($afterMissingPosition)->toBeLessThan($systemPosition);
});

it('requires a relative group label for before and after positioning', function (): void {
    CapellAdmin::registerNavigationGroup(
        label: 'Broken group',
        position: NavigationGroupPositionEnum::Before,
    );
})->throws(InvalidArgumentException::class, 'requires a relative group label');
