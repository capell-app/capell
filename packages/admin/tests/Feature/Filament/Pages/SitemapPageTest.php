<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\SitemapPage;
use Capell\Admin\Tests\Feature\Filament\Pages\Fixtures\SitemapPageFakeSitemapBuilder;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('uses sitemap labels and route protection', function (): void {
    expect(SitemapPage::getNavigationLabel())->toBe(__('capell-admin::generic.sitemap'))
        ->and(resolve(SitemapPage::class)->getTitle())->toBe(__('capell-admin::generic.sitemap'))
        ->and(resolve(SitemapPage::class)->getSubheading())->toBe(__('capell-admin::generic.sitemap_info'))
        ->and(resolve(SitemapPage::class)->getView())->toBe('capell-admin::filament.pages.sitemap');

    test()->actingAsUser();

    $this->get(SitemapPage::getUrl())->assertForbidden();
});

it('renders the selected site language through the optional sitemap builder', function (): void {
    ensureFakeSitemapBuilderForPageTests();
    grantSitemapPageAccessForPageTests();
    SitemapPageFakeSitemapBuilder::reset();

    [$site, $english, $french] = createSiteWithSitemapLanguages(
        siteName: 'Main site',
        englishDomain: 'main.test',
        frenchDomain: 'fr.main.test',
    );
    [$secondarySite, $secondaryLanguage] = createSecondarySitemapSite();

    Livewire::withQueryParams([
        'site_id' => $site->getKey(),
        'language_id' => $french->getKey(),
    ])
        ->test(SitemapPage::class)
        ->assertSet('site_id', $site->getKey())
        ->assertSet('language_id', $french->getKey())
        ->assertSee('Main site')
        ->assertSee('English')
        ->assertSee('Français')
        ->assertSee('Sitemap: Main site / Français / fr.main.test')
        ->assertSee('Child: fr')
        ->set('site_id', $secondarySite->getKey())
        ->assertSet('language_id', $secondaryLanguage->getKey())
        ->assertSee('Sitemap: Secondary site / Deutsch / secondary.test');

    expect(SitemapPageFakeSitemapBuilder::$calls)->toContain([
        'site_id' => $site->getKey(),
        'domain' => 'fr.main.test',
        'language_id' => $french->getKey(),
        'with_edit_url' => true,
    ]);
});

it('falls back to the default site and language when query parameters are omitted', function (): void {
    ensureFakeSitemapBuilderForPageTests();
    grantSitemapPageAccessForPageTests();
    SitemapPageFakeSitemapBuilder::reset();

    [$site, $english] = createSiteWithSitemapLanguages(
        siteName: 'Default site',
        englishDomain: 'default.test',
        frenchDomain: 'fr.default.test',
    );

    Livewire::test(SitemapPage::class)
        ->assertSet('site_id', $site->getKey())
        ->assertSet('language_id', $english->getKey())
        ->assertSee('Sitemap: Default site / English / default.test');
});

it('does not render stale sitemap output for invalid selections or invalid builders', function (): void {
    ensureFakeSitemapBuilderForPageTests();
    grantSitemapPageAccessForPageTests();
    SitemapPageFakeSitemapBuilder::reset();

    [$site, $english] = createSiteWithSitemapLanguages(
        siteName: 'Invalid builder site',
        englishDomain: 'invalid-builder.test',
        frenchDomain: 'fr.invalid-builder.test',
    );

    Livewire::withQueryParams([
        'site_id' => 999_999,
        'language_id' => $english->getKey(),
    ])
        ->test(SitemapPage::class)
        ->assertSet('site_id', 999_999)
        ->assertSee(__('capell-admin::generic.no_sitemap_preview'))
        ->assertSee(__('capell-admin::generic.no_sitemap_preview_description'))
        ->assertDontSee('Sitemap: Invalid builder site');

    SitemapPageFakeSitemapBuilder::$returnsCollection = false;

    Livewire::withQueryParams([
        'site_id' => $site->getKey(),
        'language_id' => $english->getKey(),
    ])
        ->test(SitemapPage::class)
        ->assertSee(__('capell-admin::generic.no_sitemap_preview'))
        ->assertDontSee('Sitemap: Invalid builder site');
});

function grantSitemapPageAccessForPageTests(): void
{
    Permission::create(['name' => 'View:SitemapPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SitemapPage');
}

function ensureFakeSitemapBuilderForPageTests(): void
{
    if (! class_exists('Capell\\SiteDiscovery\\Support\\Sitemap\\SitemapBuilder')) {
        class_alias(SitemapPageFakeSitemapBuilder::class, 'Capell\\SiteDiscovery\\Support\\Sitemap\\SitemapBuilder');
    }
}

/**
 * @return array{0: Site, 1: Language, 2: Language}
 */
function createSiteWithSitemapLanguages(string $siteName, string $englishDomain, string $frenchDomain): array
{
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()
        ->language($english)
        ->default()
        ->withTranslations([$english, $french])
        ->createOne(['name' => $siteName]);

    $site->siteDomains()->where('language_id', $english->getKey())->update([
        'scheme' => 'https',
        'domain' => $englishDomain,
        'path' => null,
        'default' => true,
    ]);
    $site->siteDomains()->where('language_id', $french->getKey())->update([
        'scheme' => 'https',
        'domain' => $frenchDomain,
        'path' => null,
    ]);

    return [$site, $english, $french];
}

/**
 * @return array{0: Site, 1: Language}
 */
function createSecondarySitemapSite(): array
{
    $language = Language::factory()->german(isDefault: true)->createOne();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language)
        ->createOne(['name' => 'Secondary site']);

    $site->siteDomains()->where('language_id', $language->getKey())->update([
        'scheme' => 'https',
        'domain' => 'secondary.test',
        'path' => null,
        'default' => true,
    ]);

    return [$site, $language];
}
