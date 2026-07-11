<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\MarketingStudioPage;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
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
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Spatie\Permission\Models\Permission;

it('keeps primary admin navigation in the approved groups', function (): void {
    expect(PageResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_websites'))
        ->and(CapellDashboard::getNavigationGroup())->toBeNull()
        ->and(CapellDashboard::shouldRegisterNavigation())->toBeTrue()
        ->and(MarketingStudioPage::getNavigationGroup())->toBeNull()
        ->and(MarketingStudioPage::getNavigationLabel())->toBe((string) __('capell-admin::navigation.marketing_studio'));
});

it('uses clearer admin navigation groups without conflicting group icons', function (): void {
    $groups = collect(CapellAdmin::getNavigationGroups())
        ->mapWithKeys(fn (NavigationGroup $group): array => [
            $group->getLabel() => $group->getIcon(),
        ]);

    expect($groups->all())->toBe([
        'capell-admin::navigation.group_dashboard' => null,
        'capell-admin::navigation.group_websites' => null,
        'capell-admin::navigation.group_content' => null,
        'capell-admin::navigation.group_workflow' => null,
        'capell-admin::navigation.group_layouts' => null,
        'capell-admin::navigation.group_marketing' => null,
        'capell-admin::navigation.group_reports' => null,
        'capell-admin::navigation.group_monitoring' => null,
        'capell-admin::navigation.group_system' => null,
    ]);
});

it('promotes workspace activity into the workspace navigation group', function (): void {
    $navigationTranslations = require __DIR__ . '/../../../resources/lang/en/navigation.php';

    expect($navigationTranslations['group_workflow'])->toBe('Workspace')
        ->and(ActivityResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_workflow'));
});

it('groups web page authoring tools in the requested order', function (): void {
    expect(CapellDashboard::getNavigationSort())->toBe(-100)
        ->and(MarketingStudioPage::getNavigationSort())->toBe(-90)
        ->and(PageResource::getNavigationSort())->toBe(-80)
        ->and(PageResource::getNavigationLabel())->toBe((string) __('capell-admin::navigation.pages'))
        ->and(PageResource::getNavigationIcon())->toBe(Heroicon::OutlinedGlobeAlt)
        ->and(PageResource::getActiveNavigationIcon())->toBe(Heroicon::GlobeAlt)
        ->and(PageResource::getNavigationParentItem())->toBeNull()
        ->and(LayoutResource::getNavigationSort())->toBe(3)
        ->and(LayoutResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_websites'))
        ->and(LayoutResource::getNavigationParentItem())->toBeNull()
        ->and(MediaResource::getNavigationSort())->toBe(4)
        ->and(MediaResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_content'))
        ->and(MediaResource::getNavigationParentItem())->toBeNull()
        ->and(SiteResource::getNavigationSort())->toBe(6)
        ->and(SiteResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(LanguageResource::getNavigationSort())->toBe(7)
        ->and(LanguageResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(ThemeResource::getNavigationSort())->toBe(8)
        ->and(ThemeResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(RedirectResource::getNavigationSort())->toBe(9)
        ->and(RedirectResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(BlueprintResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(BlueprintResource::getNavigationSort())->toBe(10);
});

it('keeps manage extensions first in system navigation', function (): void {
    expect(ExtensionsPage::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(ExtensionsPage::getNavigationLabel())->toBe((string) __('capell-admin::navigation.extensions'))
        ->and(ExtensionsPage::getNavigationItems()[0]->getSort())->toBe(PHP_INT_MIN);
});

it('keeps users top-level with roles nested underneath', function (): void {
    test()->actingAsAdmin();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();

    expect(UserResource::getNavigationGroup())->toBeNull()
        ->and(UserResource::getNavigationSort())->toBe(-70)
        ->and(RoleResource::getNavigationGroup())->toBeNull()
        ->and(RoleResource::getNavigationParentItem())->toBe((string) __('capell-admin::navigation.users'))
        ->and(RoleResource::getNavigationSort())->toBe(1)
        ->and(RoleResource::getNavigationIcon())->toBe(Heroicon::OutlinedKey)
        ->and(RoleResource::getActiveNavigationIcon())->toBe(Heroicon::Key);
});

it('keeps operational system pages out of the sidebar navigation', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    Permission::create(['name' => 'View:SiteHealthPage', 'guard_name' => 'web']);

    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage', 'View:SiteHealthPage');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();

    $systemNavigationGroup = collect(Filament::getNavigation())
        ->first(fn (NavigationGroup $group): bool => $group->getLabel() === __('capell-admin::navigation.group_system'));

    expect($systemNavigationGroup)->toBeInstanceOf(NavigationGroup::class);
    assert($systemNavigationGroup instanceof NavigationGroup);

    $systemNavigationLabels = collect($systemNavigationGroup->getItems())
        ->filter(fn (mixed $navigationItem): bool => $navigationItem instanceof NavigationItem)
        ->map(fn (NavigationItem $navigationItem): string => $navigationItem->getLabel())
        ->all();

    expect($systemNavigationLabels)
        ->toContain(SettingsPage::getNavigationLabel())
        ->not->toContain(SiteHealthPage::getNavigationLabel());
});
