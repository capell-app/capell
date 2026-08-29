<?php

declare(strict_types=1);

use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Events\PageUrlsRewritten;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;

it('creates redirects for previous page urls carried by page saved form data', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne();
    PageUrl::factory()
        ->page($page)
        ->site($page->site)
        ->language($language)
        ->state(['url' => '/new'])
        ->create();

    PageSavedAction::run($page, [
        '_previous_urls' => [
            $language->getKey() => '/old',
        ],
    ]);

    expect(PageUrl::query()->where('url', '/old')->first())
        ->not()->toBeNull()
        ->status_code->toBe(RedirectStatusCodeEnum::Permanent);
});

it('creates automatic redirects for page and descendant url rewrite maps', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()->language($language)->withTranslations([$language])->create();
    $page = Page::factory()->recycle($site)->withTranslations(slug: 'new-page')->create();
    $child = Page::factory()->recycle($site)->parent($page)->withTranslations(slug: 'child')->create();

    event(new PageUrlsRewritten(
        page: $page,
        urlChanges: [
            $language->getKey() => [
                'old' => '/old-page',
                'new' => '/new-page',
            ],
        ],
        descendantUrlChanges: [
            $child->getKey() => [
                $language->getKey() => [
                    'old' => '/old-page/child',
                    'new' => '/new-page/child',
                ],
            ],
        ],
    ));

    expect(PageUrl::query()->where('url', '/old-page')->first())
        ->not()->toBeNull()
        ->target_url->toBe('/new-page');
    expect(PageUrl::query()->where('url', '/old-page/child')->first())
        ->not()->toBeNull()
        ->target_url->toBe('/new-page/child');
});

it('does not create automatic redirects when the rewrite source opts out', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()->language($language)->withTranslations([$language])->create();
    $page = Page::factory()->recycle($site)->withTranslations(slug: 'new-page')->create();

    event(new PageUrlsRewritten(
        page: $page,
        urlChanges: [
            $language->getKey() => [
                'old' => '/old-page',
                'new' => '/new-page',
            ],
        ],
        automaticRedirectsAllowed: false,
    ));

    expect(PageUrl::query()->where('url', '/old-page')->exists())->toBeFalse();
});
