<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\SettingsPage;
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
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

it('keeps primary admin navigation in the approved groups', function (): void {
    expect(PageResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_websites'))
        ->and(CapellDashboard::getNavigationGroup())->toBeNull()
        ->and(CapellDashboard::shouldRegisterNavigation())->toBeTrue();
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
        'capell-admin::navigation.group_settings' => null,
        'capell-admin::navigation.group_system' => null,
    ]);
});

it('promotes workspace activity into the workspace navigation group', function (): void {
    $navigationTranslations = require __DIR__ . '/../../../resources/lang/en/navigation.php';

    expect($navigationTranslations['group_workflow'])->toBe('Publishing')
        ->and(ActivityResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_workflow'));
});

it('groups web page authoring tools in the requested order', function (): void {
    expect(CapellDashboard::getNavigationSort())->toBe(-100)
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
        ->and(SiteResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_websites'))
        ->and(LanguageResource::getNavigationSort())->toBe(7)
        ->and(LanguageResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(ThemeResource::getNavigationSort())->toBe(8)
        ->and(ThemeResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_websites'))
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

it('groups users under system with roles nested underneath', function (): void {
    test()->actingAsAdmin();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();

    expect(UserResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(UserResource::getNavigationSort())->toBe(-70)
        ->and(RoleResource::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_system'))
        ->and(RoleResource::getNavigationParentItem())->toBe((string) __('capell-admin::navigation.users'))
        ->and(RoleResource::getNavigationSort())->toBe(1)
        ->and(RoleResource::getNavigationIcon())->toBe(Heroicon::OutlinedKey)
        ->and(RoleResource::getActiveNavigationIcon())->toBe(Heroicon::Key);
});

it('places settings with operational system pages', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    Permission::create(['name' => 'View:SiteHealthPage', 'guard_name' => 'web']);

    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage', 'View:SiteHealthPage');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();

    $groups = Filament::getNavigation();
    $system = collect($groups)->first(fn (NavigationGroup $group): bool => $group->getLabel() === __('capell-admin::navigation.workspace_system'));
    expect($system)->toBeInstanceOf(NavigationGroup::class);
    assert($system instanceof NavigationGroup);
    expect(collect($system->getItems())->map(fn (NavigationItem $item): ?string => $item->getUrl())->all())->toContain(SettingsPage::getUrl());
});

it('keeps sidebar groups while moving secondary design tools into local navigation', function (): void {
    test()->actingAsAdmin();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
    $groups = Filament::getNavigation();
    $labels = collect($groups)->map(fn (NavigationGroup $group): ?string => $group->getLabel())->all();
    expect($labels)->toContain(__('capell-admin::navigation.group_websites'), __('capell-admin::navigation.workspace_library'), __('capell-admin::navigation.workspace_design'), __('capell-admin::navigation.workspace_system'));
    $design = collect($groups)->first(fn (NavigationGroup $group): bool => $group->getLabel() === __('capell-admin::navigation.workspace_design'));
    assert($design instanceof NavigationGroup);
    expect(collect($design->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())
        ->toBe([__('capell-admin::navigation.layouts'), __('capell-admin::navigation.themes')]);
});

it('does not expose secondary resources to an actor without resource permissions', function (): void {
    test()->actingAsUser();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
    $keys = collect(Filament::getNavigation())->flatMap(fn (NavigationGroup $group): Collection => collect($group->getItems()))
        ->map(fn (NavigationItem $item): ?string => $item->getUrl())->all();
    expect($keys)->not->toContain(MediaResource::getUrl(), LayoutResource::getUrl(), ThemeResource::getUrl());
});

it('keeps a permitted package child reachable when its parent uses a different group', function (): void {
    test()->actingAsAdmin();
    $panel = Filament::getPanel('admin');
    $panel->navigationItems([
        NavigationItem::make('Articles')->key('test.articles')->group('Website')->url('/admin/blog/article'),
        NavigationItem::make('Tags')->key('test.tags')->parentItem('Articles')->url('/admin/tags')->isActiveWhen(fn (): bool => true),
    ]);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
    $groups = Filament::getNavigation();
    $article = collect($groups)->flatMap(fn (NavigationGroup $group): Collection => collect($group->getItems()))
        ->first(fn (NavigationItem $item): bool => $item->getKey() === 'test.articles');
    expect($article)->toBeInstanceOf(NavigationItem::class);
    assert($article instanceof NavigationItem);
    expect(collect($article->getChildItems())->map(fn (NavigationItem $item): string => $item->getKey())->all())->toContain('test.tags');
});
