<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\RecordDescendantUrlRedirectsAction;
use Capell\Admin\Data\Pages\DescendantUrlRedirectRequestData;
use Capell\Core\Actions\CollectDescendantPageUrlsAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Events\PageUrlChanged;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

it('keeps redirect lookup reads bounded when one old URL is occupied', function (): void {
    // Listeners of the public URL-change event are not this action's cost.
    Event::fake([PageUrlChanged::class]);

    $measure = function (int $childCount): array {
        $site = Site::factory()->withTranslations()->create();
        $parent = Page::factory()->recycle($site)->withTranslations()->create();
        $children = Page::factory()->count($childCount)->recycle($site)->parent($parent)->withTranslations()->create();

        $snapshot = CollectDescendantPageUrlsAction::run($parent);
        foreach ($children as $index => $child) {
            rewriteCanonicalUrl($child, '/moved-' . $childCount . '/child-' . $index);
        }

        $firstUrls = $snapshot[$children->first()->getKey()];
        $occupiedLanguageId = (int) array_key_first($firstUrls);
        PageUrl::factory()->site($site)->manualRedirect()->createOne([
            'language_id' => $occupiedLanguageId,
            'url' => $firstUrls[$occupiedLanguageId],
        ]);

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = mb_strtolower(ltrim($query->sql));
        });

        $result = RecordDescendantUrlRedirectsAction::run(new DescendantUrlRedirectRequestData(
            page: $parent,
            submittedUrls: $snapshot,
            expectedUrls: $snapshot,
        ));
        $measured = $statements;

        return [
            'reads' => count(array_filter($measured, static fn (string $sql): bool => str_starts_with($sql, 'select'))),
            'redirectInserts' => count(array_filter($measured, static fn (string $sql): bool => str_starts_with($sql, 'insert') && str_contains($sql, 'page_urls'))),
            'recorded' => $result->recordedCount,
        ];
    };

    $small = $measure(2);
    $large = $measure(20);
    $extraRedirects = $large['redirectInserts'] - $small['redirectInserts'];

    // The only per-redirect read left is PageUrl's own collision guard.
    expect($small['recorded'])->toBeGreaterThan(0)
        ->and($large['recorded'])->toBe(10 * $small['recorded'])
        ->and($small['redirectInserts'])->toBe($small['recorded'] - 1)
        ->and($large['redirectInserts'])->toBe($large['recorded'] - 1)
        ->and($large['reads'] - $small['reads'])->toBeLessThanOrEqual($extraRedirects);
});
