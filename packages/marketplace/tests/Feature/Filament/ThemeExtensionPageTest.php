<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Marketplace\Actions\ApplyMarketplaceThemeToSitesAction;
use Capell\Marketplace\Filament\Pages\ThemeExtensionPage;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Permission::findOrCreate('View:ThemeExtensionPage', 'web');
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');

    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:ThemeExtensionPage', ExtensionsPage::MANAGE_PERMISSION);
});

it('applies a marketplace theme to one selected site from the theme extension page', function (): void {
    Event::fake([FrontendSurrogateKeysInvalidated::class]);
    registerThemeExtensionManifest('artisan', 'Artisan Theme');

    $previousTheme = Theme::factory()->create([
        'default' => true,
        'status' => true,
    ]);
    $selectedSite = Site::factory()->theme($previousTheme)->create(['name' => 'Selected Site']);
    $otherSite = Site::factory()->theme($previousTheme)->create(['name' => 'Other Site']);

    $page = resolve(ThemeExtensionPage::class);
    $page->mount('artisan');
    $page->scope = 'site';
    $page->siteId = (int) $selectedSite->getKey();
    $page->applyTheme();

    $theme = Theme::query()->where('key', 'artisan')->firstOrFail();

    expect($theme->name)->toBe('Artisan Theme')
        ->and($theme->status)->toBeTrue()
        ->and($theme->default)->toBeFalse()
        ->and($selectedSite->refresh()->theme_id)->toBe($theme->getKey())
        ->and($otherSite->refresh()->theme_id)->toBe($previousTheme->getKey())
        ->and($previousTheme->refresh()->default)->toBeTrue();

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === [
            'site-' . $selectedSite->getKey(),
        ],
    );
});

it('keeps selected site applies scoped when the scope update arrives stale', function (): void {
    Event::fake([FrontendSurrogateKeysInvalidated::class]);
    registerThemeExtensionManifest('corporate', 'Corporate Theme');

    $previousTheme = Theme::factory()->create([
        'default' => true,
        'status' => true,
    ]);
    $selectedSite = Site::factory()->theme($previousTheme)->create(['name' => 'Second Site']);
    $otherSite = Site::factory()->theme($previousTheme)->create(['name' => 'Corporate Site']);

    $page = resolve(ThemeExtensionPage::class);
    $page->mount('corporate');
    $page->scope = 'all';
    $page->siteId = (int) $selectedSite->getKey();
    $page->applyTheme();

    $theme = Theme::query()->where('key', 'corporate')->firstOrFail();

    expect($selectedSite->refresh()->theme_id)->toBe($theme->getKey())
        ->and($otherSite->refresh()->theme_id)->toBe($previousTheme->getKey())
        ->and($theme->default)->toBeFalse()
        ->and($previousTheme->refresh()->default)->toBeTrue();

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === [
            'site-' . $selectedSite->getKey(),
        ],
    );
});

it('clears stale selected sites when switching back to all sites', function (): void {
    registerThemeExtensionManifest('studio', 'Studio Theme');

    $page = resolve(ThemeExtensionPage::class);
    $page->mount('studio');
    $page->siteId = 123;
    $page->updatedScope('all');

    expect($page->siteId)->toBeNull();
});

it('creates a signed preview url without assigning the marketplace theme to a site', function (): void {
    registerThemeExtensionManifest('previewable', 'Previewable Theme');

    $previousTheme = Theme::factory()->create([
        'default' => true,
        'status' => true,
    ]);
    $site = Site::factory()->theme($previousTheme)->withTranslations()->create();
    $previewPage = Page::factory()->site($site)->home()->create();

    $page = resolve(ThemeExtensionPage::class);
    $page->mount('previewable');
    $page->siteId = (int) $site->getKey();

    $response = $page->previewTheme();
    $theme = Theme::query()->where('key', 'previewable')->firstOrFail();

    expect($response?->getTargetUrl())->toContain('/admin/theme-preview/' . $theme->getKey() . '/' . $site->getKey() . '/' . $previewPage->getKey())
        ->and($response?->getTargetUrl())->toContain('signature=')
        ->and($theme->default)->toBeFalse()
        ->and($site->refresh()->theme_id)->toBe($previousTheme->getKey());
});

it('requires a selected site before applying a marketplace theme with site scope', function (): void {
    Event::fake([FrontendSurrogateKeysInvalidated::class]);
    registerThemeExtensionManifest('editorial', 'Editorial Theme');

    $page = resolve(ThemeExtensionPage::class);
    $page->mount('editorial');
    $page->scope = 'site';
    $page->siteId = null;
    $page->applyTheme();

    expect(Theme::query()->where('key', 'editorial')->exists())->toBeFalse();

    Event::assertNotDispatched(FrontendSurrogateKeysInvalidated::class);
});

it('returns not found for unknown marketplace theme extension pages', function (): void {
    resolve(ThemeExtensionPage::class)->mount('missing-theme');
})->throws(NotFoundHttpException::class);

function registerThemeExtensionManifest(string $themeKey, string $displayName): void
{
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-theme/' . $themeKey . '-theme',
        surfaces: ['frontend'],
        overrides: [
            'displayName' => $displayName,
            'kind' => 'theme',
            'themeKey' => $themeKey,
        ],
    ));

    CapellCore::registerManifestPackage($manifest);

    $registry = resolve(CapellPackageRegistry::class);
    $registry->fill([
        ...$registry->all(),
        $manifest->name => $manifest,
    ]);
}

it('limits marketplace sites and theme counts to the current actor', function (): void {
    registerThemeExtensionManifest('scoped', 'Scoped Theme');
    $theme = Theme::factory()->create(['key' => 'scoped']);
    $assigned = Site::factory()->theme($theme)->create();
    Site::factory()->theme($theme)->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);
    $page = resolve(ThemeExtensionPage::class);
    $page->mount('scoped');

    expect($page->sites()->pluck('id')->all())->toBe([$assigned->getKey()])
        ->and($page->theme()?->sites_count)->toBe(1);
});

it('applies all only to accessible sites without changing the global default', function (): void {
    registerThemeExtensionManifest('scoped-all', 'Scoped All');
    $oldTheme = Theme::factory()->create(['default' => true]);
    $assigned = Site::factory()->theme($oldTheme)->create();
    $foreign = Site::factory()->theme($oldTheme)->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);
    $page = resolve(ThemeExtensionPage::class);
    $page->mount('scoped-all');
    $page->applyTheme();

    $theme = Theme::query()->where('key', 'scoped-all')->firstOrFail();

    expect($assigned->refresh()->theme_id)->toBe($theme->getKey())
        ->and($foreign->refresh()->theme_id)->toBe($oldTheme->getKey())
        ->and($oldTheme->refresh()->default)->toBeTrue()
        ->and($theme->default)->toBeFalse();
});

it('rejects a supplied foreign site for apply and preview', function (): void {
    registerThemeExtensionManifest('foreign-selection', 'Foreign Selection');
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    Page::factory()->site($foreign)->home()->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);
    $page = resolve(ThemeExtensionPage::class);
    $page->mount('foreign-selection');
    $page->scope = 'site';
    $page->siteId = (int) $foreign->getKey();
    $page->applyTheme();

    expect(Theme::query()->where('key', 'foreign-selection')->exists())->toBeFalse()
        ->and($page->previewTheme())->toBeNull();
});

it('denies direct marketplace theme writes without an actor', function (): void {
    Site::factory()->create();
    auth()->logout();

    ApplyMarketplaceThemeToSitesAction::run('guest-theme', 'Guest Theme');
})->throws(AuthorizationException::class);
