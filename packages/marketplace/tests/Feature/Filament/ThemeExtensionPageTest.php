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
use Capell\Marketplace\Filament\Pages\ThemeExtensionPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
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
