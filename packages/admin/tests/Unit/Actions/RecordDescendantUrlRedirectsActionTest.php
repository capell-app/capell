<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\RecordDescendantUrlRedirectsAction;
use Capell\Admin\Data\Pages\DescendantUrlRedirectRequestData;
use Capell\Core\Actions\CollectDescendantPageUrlsAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\DB;

uses()->group('page');

/**
 * Rewrite a descendant's canonical URL directly, simulating the rewrite
 * SetupPageUrlsAction performs when an ancestor's slug or parent changes.
 */
function rewriteCanonicalUrl(Page $page, string $newUrl): void
{
    PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->where(fn ($query) => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
        ->update(['url' => $newUrl]);
}

it('records redirects for descendant urls that changed', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    $child = Page::factory()->recycle($site)->parent($parent)->withTranslations()->create();

    $snapshot = CollectDescendantPageUrlsAction::run($parent);
    $oldUrl = current($snapshot[$child->getKey()]);

    rewriteCanonicalUrl($child, '/moved/child');

    $result = RecordDescendantUrlRedirectsAction::run(new DescendantUrlRedirectRequestData(
        page: $parent,
        submittedUrls: $snapshot,
        expectedUrls: $snapshot,
    ));

    $redirect = PageUrl::query()
        ->where('url', $oldUrl)
        ->where('type', UrlTypeEnum::Redirect)
        ->firstOrFail();

    expect($result->acceptedCount)->toBe(count($snapshot[$child->getKey()]))
        ->and($result->recordedCount)->toBeGreaterThan(0)
        ->and($redirect->pageable_id)->toBe($child->getKey())
        ->and($redirect->target_url)->toBe('/moved/child');
});

it('records redirects for nested descendant urls that changed', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    $child = Page::factory()->recycle($site)->parent($parent)->withTranslations()->create();
    $grandchild = Page::factory()->recycle($site)->parent($child)->withTranslations()->create();

    $snapshot = CollectDescendantPageUrlsAction::run($parent);
    $oldUrl = current($snapshot[$grandchild->getKey()]);

    rewriteCanonicalUrl($grandchild, '/moved/grandchild');

    $result = RecordDescendantUrlRedirectsAction::run(new DescendantUrlRedirectRequestData(
        page: $parent,
        submittedUrls: $snapshot,
        expectedUrls: $snapshot,
    ));

    $redirect = PageUrl::query()
        ->where('url', $oldUrl)
        ->where('type', UrlTypeEnum::Redirect)
        ->firstOrFail();

    expect($result->acceptedCount)->toBe(count($snapshot[$child->getKey()]) + count($snapshot[$grandchild->getKey()]))
        ->and($result->recordedCount)->toBe(count($snapshot[$grandchild->getKey()]))
        ->and($redirect->pageable_id)->toBe($grandchild->getKey())
        ->and($redirect->target_url)->toBe('/moved/grandchild');
});

it('rejects submitted urls that do not match the expected snapshot', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    $child = Page::factory()->recycle($site)->parent($parent)->withTranslations()->create();

    $snapshot = CollectDescendantPageUrlsAction::run($parent);
    $tampered = array_map(
        fn (array $urls): array => array_map(fn (): string => '/tampered', $urls),
        $snapshot,
    );

    rewriteCanonicalUrl($child, '/moved/child');

    $result = RecordDescendantUrlRedirectsAction::run(new DescendantUrlRedirectRequestData(
        page: $parent,
        submittedUrls: $tampered,
        expectedUrls: $snapshot,
    ));

    expect($result->acceptedCount)->toBe(0)
        ->and($result->recordedCount)->toBe(0)
        ->and(PageUrl::query()->where('url', '/tampered')->exists())->toBeFalse();
});

it('ignores pages that are not descendants of the edited page', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    $unrelated = Page::factory()->recycle($site)->withTranslations()->create();

    $unrelatedUrls = $unrelated->pageUrls()
        ->pluck('url', 'language_id')
        ->mapWithKeys(fn (string $url, int|string $languageId): array => [(int) $languageId => $url])
        ->all();
    $map = [$unrelated->getKey() => $unrelatedUrls];

    rewriteCanonicalUrl($unrelated, '/moved/unrelated');

    $result = RecordDescendantUrlRedirectsAction::run(new DescendantUrlRedirectRequestData(
        page: $parent,
        submittedUrls: $map,
        expectedUrls: $map,
    ));

    expect($result->acceptedCount)->toBe(0)
        ->and($result->recordedCount)->toBe(0)
        ->and(PageUrl::query()->where('type', UrlTypeEnum::Redirect)->exists())->toBeFalse();
});

it('skips descendant urls that did not change', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    Page::factory()->recycle($site)->parent($parent)->withTranslations()->create();

    $snapshot = CollectDescendantPageUrlsAction::run($parent);

    $result = RecordDescendantUrlRedirectsAction::run(new DescendantUrlRedirectRequestData(
        page: $parent,
        submittedUrls: $snapshot,
        expectedUrls: $snapshot,
    ));

    expect($result->acceptedCount)->toBeGreaterThan(0)
        ->and($result->recordedCount)->toBe(0)
        ->and(PageUrl::query()->where('type', UrlTypeEnum::Redirect)->exists())->toBeFalse();
});

/** @return array{recorded: int, reads: int, writes: int, total: int} */
function recordRedirectsForMovedSubtree(int $descendantCount): array
{
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    $children = Page::factory()->count($descendantCount)->recycle($site)->parent($parent)->withTranslations()->create();

    $snapshot = CollectDescendantPageUrlsAction::run($parent);

    foreach ($children as $index => $child) {
        rewriteCanonicalUrl($child, '/moved/child-' . $index);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = RecordDescendantUrlRedirectsAction::run(new DescendantUrlRedirectRequestData(
        page: $parent,
        submittedUrls: $snapshot,
        expectedUrls: $snapshot,
    ));
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Reads that look up pages and URLs. Each redirect is still one model insert behind the observer's
    // collision guard, which belongs to that write and is counted with it.
    $isCollisionGuard = fn (string $query): bool => str_starts_with($query, 'select exists(select * from "page_urls" where "site_id" = ? and "language_id" = ? and "url" = ?');
    $reads = $queries->reject($isCollisionGuard)->filter(fn (string $query): bool => str_starts_with(mb_strtolower(ltrim($query)), 'select')
        && (str_contains($query, '"pages"') || str_contains($query, '"page_urls"') || str_contains($query, '"languages"')))->count();
    $writes = $queries->filter(fn (string $query): bool => str_starts_with(mb_strtolower(ltrim($query)), 'insert into "page_urls"'))->count();

    return ['recorded' => $result->recordedCount, 'reads' => $reads, 'writes' => $writes, 'total' => $queries->count()];
}

it('reads a fixed number of times however large the moved subtree is', function (): void {
    $small = recordRedirectsForMovedSubtree(3);
    $large = recordRedirectsForMovedSubtree(30);

    expect($small['recorded'])->toBeGreaterThan(0)
        ->and($large['recorded'])->toBe($small['recorded'] * 10)
        ->and($large['writes'])->toBe($large['recorded'])
        ->and($large['reads'])->toBe($small['reads'])
        ->and($large['reads'])->toBeLessThanOrEqual(5)
        ->and($large['total'])->toBeLessThanOrEqual(5 + 2 * $large['writes']);
});
