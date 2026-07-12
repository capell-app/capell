<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\DB;

it('resolves a public page by site language and url', function (): void {
    $layout = Layout::factory()->createOne();
    $fixture = createPublicPageResolutionFixture([
        'page' => ['layout_id' => $layout->getKey()],
        'translation' => [
            'title' => 'About Capell',
            'content' => '<p>Public page content.</p>',
            'meta' => [
                'description' => 'About Capell meta description.',
                'slug' => 'about',
            ],
        ],
        'url' => '/about',
    ]);

    $resolution = ResolvePublicPageByUrlAction::run($fixture['site'], $fixture['language'], 'about/');

    expect($resolution->found())->toBeTrue()
        ->and($resolution->page)->toBeInstanceOf(Page::class)
        ->and($resolution->page?->getKey())->toBe($fixture['page']->getKey())
        ->and($resolution->layout)->toBeInstanceOf(Layout::class)
        ->and($resolution->layout?->getKey())->toBe($layout->getKey())
        ->and($resolution->fields->url)->toBe('/about')
        ->and($resolution->fields->title)->toBe('About Capell')
        ->and($resolution->fields->content)->toBe('<p>Public page content.</p>')
        ->and($resolution->fields->meta)->toMatchArray([
            'description' => 'About Capell meta description.',
            'slug' => 'about',
        ]);
});

it('hydrates public page url relations for rendering without lazy loading', function (): void {
    $fixture = createPublicPageResolutionFixture([
        'url' => '/about',
    ]);

    $siteDomain = SiteDomain::factory()
        ->site($fixture['site'])
        ->language($fixture['language'])
        ->create([
            'domain' => 'example.com',
            'path' => null,
            'scheme' => 'https',
        ]);

    $resolution = ResolvePublicPageByUrlAction::run($fixture['site'], $fixture['language'], '/about');
    $page = $resolution->page;

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page?->pageUrl?->relationLoaded('siteDomain'))->toBeTrue()
        ->and($page?->pageUrl?->siteDomain?->is($siteDomain))->toBeTrue()
        ->and($page?->pageUrl?->full_url)->toBe('https://example.com/about')
        ->and($page?->pageUrls->first()?->relationLoaded('siteDomain'))->toBeTrue();
});

it('returns a missing resolution for an unknown url', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $resolution = ResolvePublicPageByUrlAction::run($site, $language, '/missing');

    expect($resolution->found())->toBeFalse()
        ->and($resolution->page)->toBeNull()
        ->and($resolution->layout)->toBeNull()
        ->and($resolution->fields->url)->toBeNull()
        ->and($resolution->fields->title)->toBeNull()
        ->and($resolution->fields->content)->toBeNull();
});

it('ignores page urls with unavailable morph aliases while resolving public pages', function (): void {
    $fixture = createPublicPageResolutionFixture(['url' => '/about']);

    DB::table('page_urls')->insert([
        'site_id' => $fixture['site']->getKey(),
        'language_id' => $fixture['language']->getKey(),
        'url' => '/about',
        'pageable_type' => 'article',
        'pageable_id' => 12345,
        'status' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolution = ResolvePublicPageByUrlAction::run($fixture['site'], $fixture['language'], '/about');

    expect($resolution->found())->toBeTrue()
        ->and($resolution->page)->toBeInstanceOf(Page::class)
        ->and($resolution->page?->getKey())->toBe($fixture['page']->getKey());
});

it('does not resolve disabled or redirect urls', function (array $urlAttributes): void {
    $fixture = createPublicPageResolutionFixture([
        'url' => '/private',
        'page_url' => $urlAttributes,
    ]);

    $resolution = ResolvePublicPageByUrlAction::run($fixture['site'], $fixture['language'], '/private');

    expect($resolution->found())->toBeFalse()
        ->and($resolution->page)->toBeNull();
})->with([
    'disabled URL' => [['status' => false]],
    'redirect URL' => [['type' => UrlTypeEnum::Redirect]],
]);

it('does not resolve urls for another site or language', function (string $siteKey, string $languageKey): void {
    $fixture = createPublicPageResolutionFixture(['url' => '/scoped']);
    $otherSite = Site::factory()->createOne();
    $otherLanguage = Language::factory()->createOne();

    $site = $siteKey === 'matching' ? $fixture['site'] : $otherSite;
    $language = $languageKey === 'matching' ? $fixture['language'] : $otherLanguage;

    $resolution = ResolvePublicPageByUrlAction::run($site, $language, '/scoped');

    expect($resolution->found())->toBeFalse()
        ->and($resolution->page)->toBeNull();
})->with([
    'wrong site' => ['other', 'matching'],
    'wrong language' => ['matching', 'other'],
]);

it('does not resolve pending or expired pages', function (array $pageAttributes): void {
    $fixture = createPublicPageResolutionFixture([
        'url' => '/scheduled',
        'page' => $pageAttributes,
    ]);

    $resolution = ResolvePublicPageByUrlAction::run($fixture['site'], $fixture['language'], '/scheduled');

    expect($resolution->found())->toBeFalse()
        ->and($resolution->page)->toBeNull();
})->with([
    'pending page' => [['visible_from' => now()->addDay()]],
    'expired page' => [['visible_until' => now()->subDay()]],
]);

it('does not resolve a page without a translation for the requested language', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->site($site)->create();

    PageUrl::factory()
        ->page($page)
        ->language($language)
        ->site($site)
        ->state(['url' => '/missing-translation'])
        ->create();

    $resolution = ResolvePublicPageByUrlAction::run($site, $language, '/missing-translation');

    expect($resolution->found())->toBeFalse()
        ->and($resolution->page)->toBeNull()
        ->and($resolution->fields->title)->toBeNull()
        ->and($resolution->fields->content)->toBeNull();
});

it('does not resolve a revision page with a disabled or inaccessible type', function (array $typeAttributes): void {
    $fixture = createPublicPageResolutionFixture(['url' => '/base']);
    $revisionType = Blueprint::factory()->page()->state($typeAttributes)->create();
    $revisionPage = Page::factory()
        ->site($fixture['site'])
        ->type($revisionType)
        ->create();

    Translation::factory()
        ->translatable($revisionPage)
        ->language($fixture['language'])
        ->state([
            'title' => 'Revision title',
            'content' => '<p>Revision content.</p>',
        ])
        ->create();

    $resolution = ResolvePublicPageByUrlAction::run(
        $fixture['site'],
        $fixture['language'],
        '/base',
        $revisionPage->getKey(),
    );

    expect($resolution->found())->toBeFalse()
        ->and($resolution->page)->toBeNull();
})->with([
    'disabled type' => [['status' => false]],
    'inaccessible type' => [['meta' => ['accessible' => false]]],
]);

it('resolves a revision page only when it shares the base page uuid', function (): void {
    $fixture = createPublicPageResolutionFixture(['url' => '/base']);
    $revisionPage = Page::factory()
        ->site($fixture['site'])
        ->create(['uuid' => $fixture['page']->uuid]);

    Translation::factory()
        ->translatable($revisionPage)
        ->language($fixture['language'])
        ->state([
            'title' => 'Revision title',
            'content' => '<p>Revision content.</p>',
        ])
        ->create();

    $resolution = ResolvePublicPageByUrlAction::run(
        $fixture['site'],
        $fixture['language'],
        '/base',
        $revisionPage->getKey(),
    );

    expect($resolution->found())->toBeTrue()
        ->and($resolution->page?->getKey())->toBe($revisionPage->getKey())
        ->and($resolution->fields->title)->toBe('Revision title');
});

it('does not resolve an unrelated revision page for a url', function (): void {
    $fixture = createPublicPageResolutionFixture(['url' => '/base']);
    $otherPage = Page::factory()
        ->site($fixture['site'])
        ->create();

    Translation::factory()
        ->translatable($otherPage)
        ->language($fixture['language'])
        ->state([
            'title' => 'Other title',
            'content' => '<p>Other content.</p>',
        ])
        ->create();

    PageUrl::factory()
        ->page($otherPage)
        ->language($fixture['language'])
        ->site($fixture['site'])
        ->create(['url' => '/other']);

    $resolution = ResolvePublicPageByUrlAction::run(
        $fixture['site'],
        $fixture['language'],
        '/base',
        $otherPage->getKey(),
    );

    expect($resolution->found())->toBeFalse()
        ->and($resolution->page)->toBeNull()
        ->and($resolution->fields->title)->toBeNull();
});

/**
 * @param  array{
 *     site?: array<string, mixed>,
 *     language?: array<string, mixed>,
 *     page?: array<string, mixed>,
 *     translation?: array<string, mixed>,
 *     page_url?: array<string, mixed>,
 *     url?: string
 * }  $overrides
 * @return array{site: Site, language: Language, page: Page, translation: Translation, page_url: PageUrl}
 */
function createPublicPageResolutionFixture(array $overrides = []): array
{
    $site = Site::factory()->createOne($overrides['site'] ?? []);
    $language = Language::factory()->createOne($overrides['language'] ?? []);
    $page = Page::factory()
        ->site($site)
        ->create($overrides['page'] ?? []);

    $translation = Translation::factory()
        ->translatable($page)
        ->language($language)
        ->create($overrides['translation'] ?? []);

    $pageUrl = PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->where('language_id', $language->getKey())
        ->where('site_id', $site->getKey())
        ->firstOrFail();

    $pageUrl->fill(array_merge(
        ['url' => $overrides['url'] ?? '/page'],
        $overrides['page_url'] ?? [],
    ));
    $pageUrl->save();

    return [
        'site' => $site,
        'language' => $language,
        'page' => $page,
        'translation' => $translation,
        'page_url' => $pageUrl,
    ];
}
