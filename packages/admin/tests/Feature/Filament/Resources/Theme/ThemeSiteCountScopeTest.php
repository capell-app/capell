<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\ResolveThemeLibraryAction;
use Capell\Admin\Data\Themes\ThemeLibraryCardData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Support\Themes\ThemeCardData;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Translation\Translator;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('admin', 'theme');

beforeEach(function (): void {
    Blueprint::factory()->theme()->default()->create();
});

/**
 * @return array{theme: Theme, sites: list<Site>}
 */
function themeSiteCountScopeFixture(): array
{
    $theme = Theme::factory()->createOne(['name' => 'Shared scope theme', 'key' => 'shared-scope-theme']);
    $sites = [];

    foreach (['Alpha', 'Beta', 'Gamma'] as $label) {
        $sites[] = Site::factory()->theme($theme)->create(['name' => sprintf('Theme scope %s Site', $label)]);
    }

    return ['theme' => $theme, 'sites' => $sites];
}

function actAsThemeSiteScopedUser(Site $site): void
{
    $user = User::factory()->createOne();
    $user->assignedSiteIds = collect([(int) $site->getKey()]);
    foreach (['view_any', 'view'] as $affix) {
        $user->givePermissionTo(Permission::findOrCreate(ResourceEnum::Theme->permission($affix), 'web'));
    }

    expect($user->isGlobalAdmin())->toBeFalse();

    test()->actingAs($user);
}

function themeSiteCountFromLibrary(Theme $theme): ?int
{
    $card = collect(ResolveThemeLibraryAction::run()['installed'])
        ->first(fn (ThemeLibraryCardData $card): bool => $card->themeId === (int) $theme->getKey());

    return $card instanceof ThemeLibraryCardData ? $card->siteCount : null;
}

function themeSiteCountFromTable(Theme $theme): ?int
{
    $record = Livewire::test(ManageThemes::class)
        ->instance()
        ->getTableRecords()
        ->first(fn (Theme $record): bool => (int) $record->getKey() === (int) $theme->getKey());

    return $record instanceof Theme ? $record->sites_count : null;
}

it('shows a site-scoped user only the theme usage on their own sites', function (): void {
    ['theme' => $theme, 'sites' => $sites] = themeSiteCountScopeFixture();
    actAsThemeSiteScopedUser($sites[0]);

    expect(ThemeResource::getEloquentQuery()->findOrFail($theme->getKey())->sites_count)->toBe(1)
        ->and(themeSiteCountFromTable($theme))->toBe(1)
        ->and(themeSiteCountFromLibrary($theme))->toBe(1)
        ->and(ThemeCardData::fromTheme(Theme::query()->whereKey($theme->getKey())->firstOrFail())->siteCount)->toBe(1);
});

it('shows a global user the theme usage on every site', function (): void {
    ['theme' => $theme] = themeSiteCountScopeFixture();
    test()->actingAsAdmin();

    expect(ThemeResource::getEloquentQuery()->findOrFail($theme->getKey())->sites_count)->toBe(3)
        ->and(themeSiteCountFromTable($theme))->toBe(3)
        ->and(themeSiteCountFromLibrary($theme))->toBe(3)
        ->and(ThemeCardData::fromTheme(Theme::query()->whereKey($theme->getKey())->firstOrFail())->siteCount)->toBe(3);
});

it('still blocks deleting a theme used only by sites the scoped user cannot see', function (): void {
    $theme = Theme::factory()->createOne(['name' => 'Hidden usage theme']);
    $assignedSite = Site::factory()->create();

    foreach (['Beta', 'Gamma'] as $label) {
        Site::factory()->theme($theme)->create(['name' => sprintf('Theme scope %s Site', $label)]);
    }

    actAsThemeSiteScopedUser($assignedSite);

    $page = Livewire::test(ManageThemes::class)->instance();

    expect(ThemeResource::getEloquentQuery()->findOrFail($theme->getKey())->sites_count)->toBe(0)
        ->and($page->validateDelete($theme))->toBeFalse();
});

it('keeps an in-use legacy foundation theme listed for a scoped user who cannot see its sites', function (): void {
    // The library hides a legacy `foundation` row only when no site uses it
    // and the registered `default` definition is the foundation package.
    $registry = new ThemeRegistry;
    $registry->register(definition: new ThemeDefinitionData(
        key: 'default',
        name: 'Foundation',
        description: 'Foundation theme definition.',
        package: 'capell-app/foundation-theme',
        previewImage: '/themes/default.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'default',
                name: 'Default',
                description: 'Default preset.',
                previewImage: '/themes/default.jpg',
                values: [],
            ),
        ],
        includedSections: ['navigation', 'footer'],
        assets: ['frontend' => '/themes/default.css'],
    ));
    app()->instance(ThemeRegistry::class, $registry);

    $theme = Theme::factory()->createOne(['name' => 'Foundation', 'key' => 'foundation']);
    $assignedSite = Site::factory()->create();
    Site::factory()->theme($theme)->create();

    actAsThemeSiteScopedUser($assignedSite);

    expect(themeSiteCountFromLibrary($theme))->toBe(0);
});

it('shows only accessible usage in the theme deletion notification', function (): void {
    resolve(Translator::class)->addLines(['message.theme_not_deletable_info' => 'Accessible sites: :count'], 'en', 'capell-admin');
    ['theme' => $theme, 'sites' => $sites] = themeSiteCountScopeFixture();
    actAsThemeSiteScopedUser($sites[0]);
    $page = Livewire::test(ManageThemes::class)->instance();
    session()->forget('filament.notifications');

    expect($page->validateDelete($theme))->toBeFalse();
    $notification = collect(session()->get('filament.notifications'))->first();

    expect($notification['body'])->toBe(__('capell-admin::message.theme_not_deletable_info', ['count' => 1]));
});
