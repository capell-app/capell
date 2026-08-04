<?php

declare(strict_types=1);

use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Facades\Frontend;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

/**
 * @param  Collection<int, Language>|Language  $languages
 */
function hreflangFixture(mixed $languages): array
{
    $languages = collect($languages instanceof Language ? [$languages] : $languages);
    $default = $languages->first();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()
        ->language($default)
        ->theme($theme)
        ->withTranslations($languages, ['title' => 'Example Site'])
        ->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($languages, ['title' => 'Example Page'])
        ->create();

    return [$site, $page, $layout, $theme, $default];
}

function renderHreflangHead(Site $site, Page $page, Layout $layout, Theme $theme, Language $language): string
{
    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl', 'pageUrls.language', 'pageUrls.siteDomain', 'translation', 'translations']);

    $resourcePlan = new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty'));

    Frontend::swap(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'resourcePlan' => $resourcePlan,
            'runtimeManifest' => FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
        ],
        slug: null,
    ));

    return Blade::render(
        '<x-capell::app.head :livewire-enabled="false" :resource-plan="$resourcePlan" />',
        ['resourcePlan' => $resourcePlan],
    );
}

/**
 * @return list<array{hreflang: string, href: string}>
 */
function headAlternates(string $html): array
{
    preg_match_all('/<link\s[^>]*rel="alternate"[^>]*>/', $html, $matches);

    return array_values(collect($matches[0])
        ->map(function (string $tag): array {
            preg_match('/href="([^"]*)"/', $tag, $href);
            preg_match('/hreflang="([^"]*)"/', $tag, $hreflang);

            return ['hreflang' => $hreflang[1] ?? '', 'href' => $href[1] ?? ''];
        })
        ->all());
}

it('emits an alternate cluster for every enabled language version', function (): void {
    $languages = Language::factory()->count(2)->create();
    [$site, $page, $layout, $theme, $default] = hreflangFixture($languages);

    $alternates = headAlternates(renderHreflangHead($site, $page, $layout, $theme, $default));

    $page->load('pageUrls.language', 'pageUrls.siteDomain');
    $expected = $page->pageUrls
        ->map(fn (PageUrl $url): array => [
            'hreflang' => Str::of($url->language->locale)->lower()->replace('_', '-')->toString(),
            'href' => $url->full_url,
        ])
        ->sortBy('hreflang')
        ->values()
        ->all();

    $expected[] = [
        'hreflang' => 'x-default',
        'href' => $page->pageUrls->firstWhere('language_id', $site->language_id)->full_url,
    ];

    expect($alternates)->toBe($expected);
});

it('emits no alternates for a single language site', function (): void {
    $language = Language::factory()->createOne();
    [$site, $page, $layout, $theme] = hreflangFixture($language);

    $html = renderHreflangHead($site, $page, $layout, $theme, $language);

    expect(headAlternates($html))->toBe([])
        ->and($html)->not->toContain('hreflang=');
});

it('excludes disabled and redirect page urls from the alternate cluster', function (): void {
    $languages = Language::factory()->count(4)->create();
    [$site, $page, $layout, $theme, $default] = hreflangFixture($languages);

    $disabled = $page->pageUrls->firstWhere('language_id', $languages[2]->getKey());
    $disabled->update(['status' => false]);

    $redirect = $page->pageUrls->firstWhere('language_id', $languages[3]->getKey());
    $redirect->update(['type' => UrlTypeEnum::Redirect]);

    $alternates = headAlternates(renderHreflangHead($site, $page, $layout, $theme, $default));

    expect(collect($alternates)->pluck('href'))
        ->not->toContain($disabled->full_url)
        ->not->toContain($redirect->full_url)
        ->toHaveCount(3);
});

it('omits x-default when the default language has no page url', function (): void {
    $languages = Language::factory()->count(3)->create();
    [$site, $page, $layout, $theme, $default] = hreflangFixture($languages);

    $page->pageUrls->firstWhere('language_id', $site->language_id)->delete();

    $alternates = headAlternates(renderHreflangHead($site, $page, $layout, $theme, $default));

    expect(collect($alternates)->pluck('hreflang'))
        ->not->toContain('x-default')
        ->toHaveCount(2);
});
