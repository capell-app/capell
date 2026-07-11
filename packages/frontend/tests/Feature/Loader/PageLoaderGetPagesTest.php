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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;

beforeEach(function (): void {
    $language = Language::factory()->createOne();

    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();

    $type = Blueprint::factory()->page()->create([
        'key' => 'articles',
    ]);

    $bravoPage = Page::factory()
        ->site($site)
        ->type($type)
        ->published(CarbonImmutable::parse('2026-01-10 10:00:00'))
        ->state([
            'created_at' => CarbonImmutable::parse('2026-01-10 10:00:00'),
            'order' => 20,
        ])
        ->withTranslations($language, ['title' => 'Bravo'], slug: 'bravo-page')
        ->create();

    $alphaPage = Page::factory()
        ->site($site)
        ->type($type)
        ->published(CarbonImmutable::parse('2026-03-10 10:00:00'))
        ->state([
            'created_at' => CarbonImmutable::parse('2026-03-10 10:00:00'),
            'order' => 30,
        ])
        ->withTranslations($language, ['title' => 'Alpha'], slug: 'alpha-page')
        ->create();

    $charliePage = Page::factory()
        ->site($site)
        ->type($type)
        ->published(CarbonImmutable::parse('2026-02-10 10:00:00'))
        ->state([
            'created_at' => CarbonImmutable::parse('2026-02-10 10:00:00'),
            'order' => 10,
        ])
        ->withTranslations($language, ['title' => 'Charlie'], slug: 'charlie-page')
        ->create();

    $this->language = $language;
    $this->site = $site;
    $this->type = $type;
    $this->pageIds = [$bravoPage->id, $alphaPage->id, $charliePage->id];
    $this->expectedAlphabeticalOrder = [$alphaPage->id, $bravoPage->id, $charliePage->id];
    $this->expectedLatestOrder = [$alphaPage->id, $charliePage->id, $bravoPage->id];
    $this->expectedOldestOrder = [$bravoPage->id, $charliePage->id, $alphaPage->id];
    $this->expectedOrderScopeOrder = [$charliePage->id, $bravoPage->id, $alphaPage->id];

    $parentPage = Page::factory()
        ->site($site)
        ->type($type)
        ->published(CarbonImmutable::parse('2026-03-01 00:00:00'))
        ->state(['order' => 1])
        ->withTranslations($language, ['title' => 'Parent'], slug: 'parent-page')
        ->create();

    $childOne = Page::factory()
        ->site($site)
        ->parent($parentPage)
        ->type($type)
        ->published(CarbonImmutable::parse('2026-03-01 00:00:00'))
        ->state(['order' => 2])
        ->withTranslations($language, ['title' => 'Child One'], slug: 'child-one')
        ->create();

    $childTwo = Page::factory()
        ->site($site)
        ->parent($parentPage)
        ->type($type)
        ->published(CarbonImmutable::parse('2026-03-01 00:00:00'))
        ->state(['order' => 3])
        ->withTranslations($language, ['title' => 'Child Two'], slug: 'child-two')
        ->create();

    $groupedType = Blueprint::factory()->page()->create([
        'key' => 'grouped-articles',
        'group' => 'article',
    ]);

    $groupedPage = Page::factory()
        ->site($site)
        ->type($groupedType)
        ->published(CarbonImmutable::parse('2026-03-01 00:00:00'))
        ->state(['order' => 40])
        ->withTranslations($language, ['title' => 'Grouped'], slug: 'grouped-page')
        ->create();

    $nonListableType = Blueprint::factory()->page()->meta(['listable' => false])->create([
        'key' => 'private-articles',
    ]);

    $nonListablePage = Page::factory()
        ->site($site)
        ->type($nonListableType)
        ->published(CarbonImmutable::parse('2026-03-01 00:00:00'))
        ->state(['order' => 50])
        ->withTranslations($language, ['title' => 'Hidden'], slug: 'hidden-page')
        ->create();

    $this->parentPage = $parentPage;
    $this->childOne = $childOne;
    $this->childTwo = $childTwo;
    $this->groupedType = $groupedType;
    $this->groupedPage = $groupedPage;
    $this->nonListableType = $nonListableType;
    $this->nonListablePage = $nonListablePage;
});

function loadPagesForOrderingTest(
    Language $language,
    Site $site,
    Blueprint $type,
    array $pageIds,
    ?PageOrderEnum $ordering,
    bool $withPagination = false,
): Collection|LengthAwarePaginator {
    return PageLoader::getPages(
        language: $language,
        site: $site,
        limit: $withPagination ? 2 : null,
        paginationPage: $withPagination ? 1 : null,
        ordering: $ordering,
        pageType: 'page',
        typeKey: $type->key,
        withPagination: $withPagination,
        paginationKey: 'frontend-pages',
        cacheKeyPrepend: 'ordering-test',
        useCache: false,
        modifyQuery: function (Builder $query) use ($pageIds): void {
            $query->whereIn('id', $pageIds);
        },
    );
}

function loadPagesWithAllParams(
    Language $language,
    Site $site,
    ?Page $page,
    ?string $type,
    ?int $limit,
    ?int $paginationPage,
    ?PageOrderEnum $ordering,
    ?string $pageType,
    null|string|BackedEnum $pageGroup,
    ?string $typeKey,
    bool $optionalLanguage,
    bool $withChildrenCount,
    bool $withChildren,
    bool $withImage,
    bool $withPagination,
    bool $withParent,
    bool $withDate,
    bool $onlyListableTypes,
    string $paginationKey,
    string $cacheKeyPrepend,
    ?string $morphModel,
    bool $useCache,
    ?Closure $modifyQuery,
): Collection|LengthAwarePaginator {
    return PageLoader::getPages(
        language: $language,
        site: $site,
        page: $page,
        type: $type,
        limit: $limit,
        paginationPage: $paginationPage,
        ordering: $ordering,
        pageType: $pageType,
        pageGroup: $pageGroup,
        typeKey: $typeKey,
        optionalLanguage: $optionalLanguage,
        withChildrenCount: $withChildrenCount,
        withChildren: $withChildren,
        withImage: $withImage,
        withPagination: $withPagination,
        withParent: $withParent,
        withDate: $withDate,
        onlyListableTypes: $onlyListableTypes,
        paginationKey: $paginationKey,
        cacheKeyPrepend: $cacheKeyPrepend,
        morphModel: $morphModel,
        useCache: $useCache,
        modifyQuery: $modifyQuery,
    );
}

it('orders pages alphabetically when ordering is alphabetical', function (): void {
    $pages = loadPagesForOrderingTest($this->language, $this->site, $this->type, $this->pageIds, PageOrderEnum::Alphabetical);

    expect($pages->pluck('id')->all())->toBe($this->expectedAlphabeticalOrder);
});

it('orders pages by latest created date when ordering is latest', function (): void {
    $pages = loadPagesForOrderingTest($this->language, $this->site, $this->type, $this->pageIds, PageOrderEnum::Latest);

    expect($pages->pluck('id')->all())->toBe($this->expectedLatestOrder);
});

it('skips pages whose type does not opt into next and previous navigation', function (): void {
    $navigableType = Blueprint::factory()->page()->meta(['with_next_prev' => true])->create([
        'key' => 'navigable-pages',
    ]);

    $nonNavigableType = Blueprint::factory()->page()->meta(['with_next_prev' => false])->create([
        'key' => 'hidden-pages',
    ]);

    $currentPage = Page::factory()
        ->site($this->site)
        ->type($navigableType)
        ->parent($this->parentPage)
        ->published(CarbonImmutable::parse('2026-04-01 10:00:00'))
        ->state(['order' => 4])
        ->withTranslations($this->language, ['title' => 'Current'], slug: 'current-page')
        ->create();

    Page::factory()
        ->site($this->site)
        ->type($nonNavigableType)
        ->parent($this->parentPage)
        ->published(CarbonImmutable::parse('2026-04-02 10:00:00'))
        ->state(['order' => 5])
        ->withTranslations($this->language, ['title' => 'Hidden'], slug: 'hidden-between-page')
        ->create();

    $nextPage = Page::factory()
        ->site($this->site)
        ->type($navigableType)
        ->parent($this->parentPage)
        ->published(CarbonImmutable::parse('2026-04-03 10:00:00'))
        ->state(['order' => 6])
        ->withTranslations($this->language, ['title' => 'Next'], slug: 'next-page')
        ->create();

    expect(PageLoader::getNextPage($currentPage, $this->site, $this->language))
        ->toBeInstanceOf(Page::class)
        ->id->toEqual($nextPage->id);
});

it('orders pages by oldest created date when ordering is oldest', function (): void {
    $pages = loadPagesForOrderingTest($this->language, $this->site, $this->type, $this->pageIds, PageOrderEnum::Oldest);

    expect($pages->pluck('id')->all())->toBe($this->expectedOldestOrder);
});

it('orders pages by order scope when ordering is order', function (): void {
    $pages = loadPagesForOrderingTest($this->language, $this->site, $this->type, $this->pageIds, PageOrderEnum::Default);

    expect($pages->pluck('id')->all())->toBe($this->expectedOrderScopeOrder);
});

it('uses latest ordering when ordering is null', function (): void {
    $pages = loadPagesForOrderingTest($this->language, $this->site, $this->type, $this->pageIds, null);

    expect($pages->pluck('id')->all())->toBe($this->expectedLatestOrder);
});

it('supports pagination options while preserving ordering', function (): void {
    $pages = loadPagesForOrderingTest(
        $this->language,
        $this->site,
        $this->type,
        $this->pageIds,
        PageOrderEnum::Latest,
        true,
    );

    assert($pages instanceof LengthAwarePaginator);

    expect($pages)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($pages->getCollection()->pluck('id')->all())
        ->toBe(array_slice($this->expectedLatestOrder, 0, 2));
});

it('returns children when type is children and page is provided', function (): void {
    $pages = loadPagesWithAllParams(
        language: $this->language,
        site: $this->site,
        page: $this->parentPage,
        type: 'children',
        limit: null,
        paginationPage: null,
        ordering: null,
        pageType: 'page',
        pageGroup: null,
        typeKey: $this->type->key,
        optionalLanguage: false,
        withChildrenCount: false,
        withChildren: false,
        withImage: false,
        withPagination: false,
        withParent: true,
        withDate: false,
        onlyListableTypes: true,
        paginationKey: 'children-pages',
        cacheKeyPrepend: 'children-case',
        morphModel: Page::class,
        useCache: false,
        modifyQuery: function (Builder|HasMany $query): void {
            $query->whereIn('id', [$this->childOne->id, $this->childTwo->id]);
        },
    );

    expect($pages->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->childOne->id, $this->childTwo->id])->sort()->values()->all())
        ->and($pages->first()->relationLoaded('parent'))->toBeTrue();
});

it('returns siblings when type is siblings and page is provided', function (): void {
    $pages = loadPagesWithAllParams(
        language: $this->language,
        site: $this->site,
        page: $this->childOne,
        type: 'siblings',
        limit: null,
        paginationPage: null,
        ordering: null,
        pageType: 'page',
        pageGroup: null,
        typeKey: $this->type->key,
        optionalLanguage: false,
        withChildrenCount: false,
        withChildren: false,
        withImage: false,
        withPagination: false,
        withParent: false,
        withDate: false,
        onlyListableTypes: true,
        paginationKey: 'siblings-pages',
        cacheKeyPrepend: 'siblings-case',
        morphModel: Page::class,
        useCache: false,
        modifyQuery: function (Builder|HasMany $query): void {
            $query->whereIn('id', [$this->childOne->id, $this->childTwo->id]);
        },
    );

    expect($pages->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->childOne->id, $this->childTwo->id])->sort()->values()->all());
});

it('filters by typeKey and pageGroup options', function (): void {
    $pages = loadPagesWithAllParams(
        language: $this->language,
        site: $this->site,
        page: null,
        type: null,
        limit: null,
        paginationPage: null,
        ordering: null,
        pageType: 'page',
        pageGroup: 'article',
        typeKey: $this->groupedType->key,
        optionalLanguage: false,
        withChildrenCount: false,
        withChildren: false,
        withImage: false,
        withPagination: false,
        withParent: false,
        withDate: false,
        onlyListableTypes: true,
        paginationKey: 'group-pages',
        cacheKeyPrepend: 'group-case',
        morphModel: Page::class,
        useCache: false,
        modifyQuery: function (Builder $query): void {
            $query->whereIn('id', [$this->groupedPage->id, $this->nonListablePage->id]);
        },
    );

    expect($pages->pluck('id')->all())->toBe([$this->groupedPage->id]);
});

it('respects onlyListableTypes option when false', function (): void {
    $pages = loadPagesWithAllParams(
        language: $this->language,
        site: $this->site,
        page: null,
        type: null,
        limit: null,
        paginationPage: null,
        ordering: null,
        pageType: 'page',
        pageGroup: null,
        typeKey: $this->nonListableType->key,
        optionalLanguage: false,
        withChildrenCount: false,
        withChildren: false,
        withImage: false,
        withPagination: false,
        withParent: false,
        withDate: false,
        onlyListableTypes: false,
        paginationKey: 'non-listable-pages',
        cacheKeyPrepend: 'non-listable-case',
        morphModel: Page::class,
        useCache: false,
        modifyQuery: function (Builder $query): void {
            $query->where('id', $this->nonListablePage->id);
        },
    );

    expect($pages->pluck('id')->all())->toBe([$this->nonListablePage->id]);
});

it('loads optional relations flags when requested', function (): void {
    $pages = loadPagesWithAllParams(
        language: $this->language,
        site: $this->site,
        page: null,
        type: null,
        limit: null,
        paginationPage: null,
        ordering: null,
        pageType: 'page',
        pageGroup: null,
        typeKey: $this->type->key,
        optionalLanguage: true,
        withChildrenCount: true,
        withChildren: true,
        withImage: true,
        withPagination: false,
        withParent: false,
        withDate: true,
        onlyListableTypes: true,
        paginationKey: 'flags-pages',
        cacheKeyPrepend: 'flags-case',
        morphModel: Page::class,
        useCache: true,
        modifyQuery: function (Builder $query): void {
            $query->where('id', $this->parentPage->id);
        },
    );

    $loadedPage = $pages->first();

    expect($loadedPage?->relationLoaded('children'))->toBeTrue()
        ->and($loadedPage?->relationLoaded('image'))->toBeTrue()
        ->and($loadedPage?->relationLoaded('creator'))->toBeTrue()
        ->and($loadedPage?->children_count)->toBe(2);
});

it('falls back to default page model when morphModel is invalid', function (): void {
    $pages = loadPagesWithAllParams(
        language: $this->language,
        site: $this->site,
        page: null,
        type: null,
        limit: null,
        paginationPage: null,
        ordering: PageOrderEnum::Latest,
        pageType: 'page',
        pageGroup: null,
        typeKey: $this->type->key,
        optionalLanguage: false,
        withChildrenCount: false,
        withChildren: false,
        withImage: false,
        withPagination: false,
        withParent: false,
        withDate: false,
        onlyListableTypes: true,
        paginationKey: 'fallback-pages',
        cacheKeyPrepend: 'fallback-case',
        morphModel: stdClass::class,
        useCache: false,
        modifyQuery: function (Builder $query): void {
            $query->whereIn('id', $this->pageIds);
        },
    );

    expect($pages)->toBeInstanceOf(Collection::class)
        ->and($pages->pluck('id')->all())->toBe($this->expectedLatestOrder);
});

it('uses configured pagination limit when pagination is enabled and limit is zero', function (): void {
    config()->set('capell-frontend.pagination_limit', 1);

    $pages = loadPagesWithAllParams(
        language: $this->language,
        site: $this->site,
        page: null,
        type: null,
        limit: 0,
        paginationPage: 1,
        ordering: PageOrderEnum::Latest,
        pageType: 'page',
        pageGroup: null,
        typeKey: $this->type->key,
        optionalLanguage: false,
        withChildrenCount: false,
        withChildren: false,
        withImage: false,
        withPagination: true,
        withParent: false,
        withDate: false,
        onlyListableTypes: true,
        paginationKey: 'limit-pages',
        cacheKeyPrepend: 'limit-case',
        morphModel: Page::class,
        useCache: false,
        modifyQuery: function (Builder $query): void {
            $query->whereIn('id', $this->pageIds);
        },
    );

    assert($pages instanceof LengthAwarePaginator);

    expect($pages)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($pages->perPage())->toBe(1)
        ->and($pages->getCollection()->count())->toBe(1);
});
