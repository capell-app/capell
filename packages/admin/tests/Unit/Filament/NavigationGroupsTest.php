<?php

declare(strict_types=1);

use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Filament\Pages\AnalyticsDashboard;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\RecentlyDeletedPage;
use Capell\Admin\Filament\Pages\SecurityPage;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Filament\Pages\UpgradePage;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\BlockTemplates\BlockTemplateResource;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Admin\Support\Navigation\NavigationLimits;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Collection;

/** @return Collection<int, NavigationGroup> */
function bootAdminNavigation(): Collection
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();

    return collect(Filament::getNavigation());
}

it('places every core screen in its agreed group', function (string $class, ?NavigationGroupEnum $group): void {
    expect($class::getNavigationGroup())->toBe($group);
})->with([
    [CapellDashboard::class, null],
    [PageResource::class, NavigationGroupEnum::Website],
    [RedirectResource::class, NavigationGroupEnum::Website],
    [MediaResource::class, NavigationGroupEnum::Media],
    [LayoutResource::class, NavigationGroupEnum::Design],
    [ThemeResource::class, NavigationGroupEnum::Design],
    [BlockTemplateResource::class, NavigationGroupEnum::Design],
    [BlueprintResource::class, NavigationGroupEnum::Design],
    [AnalyticsDashboard::class, NavigationGroupEnum::Reports],
    [UserResource::class, NavigationGroupEnum::Users],
    [ActivityResource::class, NavigationGroupEnum::Users],
    [SettingsPage::class, NavigationGroupEnum::Settings],
    [SiteResource::class, NavigationGroupEnum::Settings],
    [LanguageResource::class, NavigationGroupEnum::Settings],
    [SiteHealthPage::class, NavigationGroupEnum::Settings],
    [SecurityPage::class, NavigationGroupEnum::Settings],
    [ExtensionsPage::class, NavigationGroupEnum::Settings],
    [UpgradePage::class, NavigationGroupEnum::Settings],
]);

it('builds the administrator sidebar with the dashboard first and the fixed groups in order', function (): void {
    test()->actingAsAdmin();
    $groups = bootAdminNavigation();

    $labels = $groups->map(fn (NavigationGroup $group): ?string => $group->getLabel())->all();
    $expectedOrder = array_map(fn (NavigationGroupEnum $case): string => $case->getLabel(), NavigationGroupEnum::cases());

    expect($labels[0])->toBeNull()
        // Installer pages only appear before Capell is installed, which the test app reports.
        ->and(collect($groups->first()?->getItems())->map(fn (NavigationItem $item): string => $item->getKey())
            ->reject(fn (string $key): bool => str_starts_with($key, 'Capell\\Installer\\'))->values()->all())->toBe([CapellDashboard::class])
        ->and(array_values(array_intersect($expectedOrder, $labels)))->toBe(array_values(array_filter($labels)))
        ->and(collect($groups->firstWhere(fn (NavigationGroup $group): bool => $group->getLabel() === 'Website')?->getItems())
            ->map(fn (NavigationItem $item): string => $item->getKey())->take(2)->all())
        ->toBe([PageResource::class, RedirectResource::class]);
});

it('nests roles under users in the users group', function (): void {
    test()->actingAsAdmin();
    $users = bootAdminNavigation()->firstWhere(fn (NavigationGroup $group): bool => $group->getLabel() === 'Users');

    expect($users)->toBeInstanceOf(NavigationGroup::class);
    assert($users instanceof NavigationGroup);
    $userItem = collect($users->getItems())->first(fn (NavigationItem $item): bool => $item->getKey() === UserResource::class);
    expect(collect($userItem?->getChildItems())->map(fn (NavigationItem $item): string => $item->getKey())->all())->toContain(RoleResource::class);
});

it('moves recently deleted from the groups to the sidebar footer', function (): void {
    test()->actingAsAdmin();
    $keys = bootAdminNavigation()->flatMap(fn (NavigationGroup $group): Collection => collect($group->getItems()))
        ->map(fn (NavigationItem $item): string => $item->getKey())->all();

    expect(RecentlyDeletedPage::shouldRegisterNavigation())->toBeFalse()
        ->and($keys)->not->toContain(RecentlyDeletedPage::class);
});

it('keeps the core sidebar within the agreed limits', function (): void {
    test()->actingAsAdmin();
    $groups = bootAdminNavigation()->filter(fn (NavigationGroup $group): bool => $group->getLabel() !== null);
    $singleItemGroups = array_map(fn (NavigationGroupEnum $case): string => $case->getLabel(), NavigationLimits::singleItemGroups());

    expect($groups->count())->toBeLessThanOrEqual(NavigationLimits::maxGroups());

    foreach ($groups as $group) {
        $count = collect($group->getItems())->count();
        expect($count)->toBeLessThanOrEqual(NavigationLimits::maxItemsPerGroup(), $group->getLabel() . ' is over the item limit');

        if (! in_array($group->getLabel(), $singleItemGroups, true)) {
            expect($count)->toBeGreaterThanOrEqual(NavigationLimits::minItemsPerGroup(), $group->getLabel() . ' has too few items');
        }
    }
});

it('hides groups the person has nothing to open in', function (): void {
    test()->actingAsUser();
    $urls = bootAdminNavigation()->flatMap(fn (NavigationGroup $group): Collection => collect($group->getItems()))
        ->map(fn (NavigationItem $item): ?string => $item->getUrl())->all();

    expect($urls)->not->toContain(MediaResource::getUrl())->not->toContain(LayoutResource::getUrl());
    foreach (bootAdminNavigation() as $group) {
        expect($group->getItems())->not->toBeEmpty();
    }
});
