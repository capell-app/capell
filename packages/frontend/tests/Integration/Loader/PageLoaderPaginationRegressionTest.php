<?php

declare(strict_types=1);

use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Loader\PageLoader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Call PageLoader::getPages with pagination enabled and a fixed limit of 3.
 *
 * @param  int[]  $pageIds  Restrict results to these IDs (to isolate test data).
 */
function loadPaginatedPages(
    Language $language,
    Site $site,
    Blueprint $type,
    array $pageIds,
    int $limit,
    ?int $paginationPage,
): LengthAwarePaginator {
    /** @var LengthAwarePaginator<Page> */
    return PageLoader::getPages(
        language: $language,
        site: $site,
        limit: $limit,
        paginationPage: $paginationPage,
        ordering: PageOrderEnum::Latest,
        pageType: 'page',
        typeKey: $type->key,
        withPagination: true,
        paginationKey: 'pages',
        cacheKeyPrepend: 'pagination-regression-test',
        useCache: false,
        modifyQuery: function (Builder $query) use ($pageIds): void {
            $query->whereIn('id', $pageIds);
        },
    );
}

// ---------------------------------------------------------------------------
// Setup — 7 pages with distinct published dates (newest → oldest by ID desc)
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $language = Language::factory()->createOne();

    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();

    $type = Blueprint::factory()->page()->create(['key' => 'pagination-reg']);

    // Pages created newest-first so that publishedLatest returns them as page1[0..2], page2[3..5], page3[6]
    $pages = collect();
    foreach (range(1, 7) as $index) {
        $date = CarbonImmutable::parse('2026-01-01 00:00:00')->subDays($index - 1);
        $pages->push(
            Page::factory()
                ->site($site)
                ->blueprint($type)
                ->published($date)
                ->state(['created_at' => $date])
                ->withTranslations($language, ['title' => 'Page ' . $index], slug: 'page-' . $index)
                ->create(),
        );
    }

    // After publishedLatest ordering: $pages[0] is newest (ID with highest date), $pages[6] is oldest.
    $this->language = $language;
    $this->site = $site;
    $this->type = $type;
    // IDs in expected latest order (newest date first, which equals creation order here)
    $this->allPageIds = $pages->pluck('id')->all();
    // Explicit per-page expectations (limit=3)
    $this->expectedPage1Ids = $pages->slice(0, 3)->pluck('id')->all();
    $this->expectedPage2Ids = $pages->slice(3, 3)->pluck('id')->all();
    $this->expectedPage3Ids = $pages->slice(6, 1)->pluck('id')->all();
});

// ---------------------------------------------------------------------------
// Bug 1: pagination offset — paginationPage: 0 must not return negative offset
// ---------------------------------------------------------------------------

it('returns page 1 items when paginationPage is 0 (the int-cast fallback bug)', function (): void {
    // Before the fix: max(1, 0 ?? 1) would be max(1, 0) = 1 is correct now,
    // but previously $currentPage = 0 ?? 1 = 0 (not null), giving offset = -1*3 = -3.
    $paginator = loadPaginatedPages($this->language, $this->site, $this->type, $this->allPageIds, 3, 0);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->currentPage())->toBe(1)
        ->and($paginator->getCollection()->pluck('id')->all())->toBe($this->expectedPage1Ids);
});

it('returns page 1 items when paginationPage is null', function (): void {
    $paginator = loadPaginatedPages($this->language, $this->site, $this->type, $this->allPageIds, 3, null);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->currentPage())->toBe(1)
        ->and($paginator->getCollection()->pluck('id')->all())->toBe($this->expectedPage1Ids);
});

it('returns page 1 items when paginationPage is explicitly 1', function (): void {
    $paginator = loadPaginatedPages($this->language, $this->site, $this->type, $this->allPageIds, 3, 1);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->currentPage())->toBe(1)
        ->and($paginator->getCollection()->pluck('id')->all())->toBe($this->expectedPage1Ids);
});

it('returns correct items for each page in a multi-page scenario', function (): void {
    $page1 = loadPaginatedPages($this->language, $this->site, $this->type, $this->allPageIds, 3, 1);
    $page2 = loadPaginatedPages($this->language, $this->site, $this->type, $this->allPageIds, 3, 2);
    $page3 = loadPaginatedPages($this->language, $this->site, $this->type, $this->allPageIds, 3, 3);

    expect($page1->getCollection()->pluck('id')->all())->toBe($this->expectedPage1Ids)
        ->and($page2->getCollection()->pluck('id')->all())->toBe($this->expectedPage2Ids)
        ->and($page3->getCollection()->pluck('id')->all())->toBe($this->expectedPage3Ids);
});

// ---------------------------------------------------------------------------
// Bug 2: non-deterministic ordering — identical dates must sort by ID desc
// ---------------------------------------------------------------------------

it('orders pages with identical published dates deterministically by id descending', function (): void {
    // Create three pages with the exact same published timestamp so the primary
    // sort produces a tie — the tiebreaker (ORDER BY id DESC) must resolve it.
    $sharedDate = CarbonImmutable::parse('2025-06-15 12:00:00');

    $tieType = Blueprint::factory()->page()->create(['key' => 'tie-breaker-reg']);

    $tiePageFirst = Page::factory()
        ->site($this->site)
        ->blueprint($tieType)
        ->published($sharedDate)
        ->state(['created_at' => $sharedDate])
        ->withTranslations($this->language, ['title' => 'Tie First'], slug: 'tie-first')
        ->create();

    $tiePageSecond = Page::factory()
        ->site($this->site)
        ->blueprint($tieType)
        ->published($sharedDate)
        ->state(['created_at' => $sharedDate])
        ->withTranslations($this->language, ['title' => 'Tie Second'], slug: 'tie-second')
        ->create();

    $tiePageThird = Page::factory()
        ->site($this->site)
        ->blueprint($tieType)
        ->published($sharedDate)
        ->state(['created_at' => $sharedDate])
        ->withTranslations($this->language, ['title' => 'Tie Third'], slug: 'tie-third')
        ->create();

    $tiePageIds = [$tiePageFirst->id, $tiePageSecond->id, $tiePageThird->id];

    // Run multiple times to catch non-determinism (SQLite can return stable
    // order, but two runs with different WHERE orderings confirm the tiebreaker).
    $runA = PageLoader::getPages(
        language: $this->language,
        site: $this->site,
        ordering: PageOrderEnum::Latest,
        pageType: 'page',
        typeKey: $tieType->key,
        useCache: false,
        modifyQuery: function (Builder $query) use ($tiePageIds): void {
            $query->whereIn('id', $tiePageIds);
        },
    );

    $runB = PageLoader::getPages(
        language: $this->language,
        site: $this->site,
        ordering: PageOrderEnum::Latest,
        pageType: 'page',
        typeKey: $tieType->key,
        useCache: false,
        modifyQuery: function (Builder $query) use ($tiePageIds): void {
            // Same IDs but reversed in the WHERE clause to exercise different query plans.
            $query->whereIn('id', array_reverse($tiePageIds));
        },
    );

    // IDs must be highest-first because scopePublishedLatest adds ORDER BY id DESC as tiebreaker.
    $expectedOrder = [
        $tiePageThird->id,
        $tiePageSecond->id,
        $tiePageFirst->id,
    ];

    expect($runA->pluck('id')->all())->toBe($expectedOrder)
        ->and($runB->pluck('id')->all())->toBe($expectedOrder);
});
