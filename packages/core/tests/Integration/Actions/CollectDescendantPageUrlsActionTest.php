<?php

declare(strict_types=1);

use Capell\Core\Actions\CollectDescendantPageUrlsAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;

it('snapshots canonical urls for every descendant keyed by page then language', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    $child = Page::factory()->recycle($site)->parent($parent)->withTranslations()->create();
    $grandchild = Page::factory()->recycle($site)->parent($child)->withTranslations()->create();

    $snapshots = CollectDescendantPageUrlsAction::run($parent);

    $expectedFor = fn (Page $page): array => $page->pageUrls()
        ->where(fn ($query) => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
        ->pluck('url', 'language_id')
        ->mapWithKeys(fn (string $url, int|string $languageId): array => [(int) $languageId => $url])
        ->all();

    expect($snapshots)
        ->toHaveKeys([$child->getKey(), $grandchild->getKey()])
        ->not()->toHaveKey($parent->getKey())
        ->and($snapshots[$child->getKey()])->toBe($expectedFor($child))
        ->and($snapshots[$grandchild->getKey()])->toBe($expectedFor($grandchild));
});

it('excludes redirect urls from descendant snapshots', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    $child = Page::factory()->recycle($site)->parent($parent)->withTranslations()->create();

    $canonical = $child->pageUrls()->firstOrFail();

    PageUrl::factory()
        ->page($child)
        ->site($site)
        ->language($canonical->language)
        ->state([
            'url' => '/legacy-child-url',
            'type' => UrlTypeEnum::Redirect,
        ])
        ->create();

    $snapshots = CollectDescendantPageUrlsAction::run($parent);

    expect($snapshots[$child->getKey()] ?? [])
        ->not()->toContain('/legacy-child-url');
});

it('snapshots each descendant url under its own language id', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->create();
    $parent = Page::factory()->recycle($site)->withTranslations([$english, $french])->create();
    $child = Page::factory()->recycle($site)->parent($parent)->withTranslations([$english, $french])->create();

    $snapshots = CollectDescendantPageUrlsAction::run($parent);

    $urlsByLanguage = $child->pageUrls()
        ->where(fn ($query) => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
        ->pluck('url', 'language_id')
        ->mapWithKeys(fn (string $url, int|string $languageId): array => [(int) $languageId => $url])
        ->all();

    expect($snapshots[$child->getKey()])
        ->toBe($urlsByLanguage)
        ->toHaveKeys([$english->getKey(), $french->getKey()])
        ->and($snapshots[$child->getKey()][$english->getKey()])
        ->not()->toBe($snapshots[$child->getKey()][$french->getKey()]);
});

it('returns an empty snapshot for a page without descendants', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $page = Page::factory()->recycle($site)->withTranslations()->create();

    expect(CollectDescendantPageUrlsAction::run($page))->toBe([]);
});
