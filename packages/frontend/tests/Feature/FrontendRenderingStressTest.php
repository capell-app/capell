<?php

declare(strict_types=1);

use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Support\Loader\PageLoader;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('renders the requested frontend page from a large CMS dataset with bounded queries', function (): void {
    $fixture = seedLargeFrontendRenderingDataset(pageCount: 1500);
    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $response = $this->get('http://large-capell.test/target-stress-page');

    $response
        ->assertOk()
        ->assertSee('Large CMS target page')
        ->assertSee('Rendered from Large CMS target page', false)
        ->assertDontSee('Large CMS filler page 1499')
        ->assertDontSee('<script>alert("target")</script>', false);

    expect(Page::query()->count())->toBe($fixture['pageCount'])
        ->and($queryCount)->toBeLessThan(85);
});

it('never touches the event-sourcing tables when rendering a page anonymously', function (): void {
    // Public Output Safety: an anonymous render must read only the public read
    // model. The event store and its read models (history/workflow) are an
    // admin-only authoring concern and must never appear in a guest's queries.
    seedLargeFrontendRenderingDataset(pageCount: 1);

    $forbiddenTables = ['stored_events', 'page_revisions', 'page_workflow_states', 'snapshots'];
    $offendingQueries = [];

    DB::listen(function ($query) use ($forbiddenTables, &$offendingQueries): void {
        foreach ($forbiddenTables as $table) {
            if (str_contains((string) $query->sql, $table)) {
                $offendingQueries[] = $query->sql;
            }
        }
    });

    $this->assertGuest();
    $this->get('http://large-capell.test/target-stress-page')->assertOk();

    expect($offendingQueries)->toBe([]);
});

it('keeps frontend listings paginated against a large CMS dataset', function (): void {
    $fixture = seedLargeFrontendRenderingDataset(pageCount: 1500);
    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $pages = PageLoader::getPages(
        language: $fixture['language'],
        site: $fixture['site'],
        limit: 12,
        paginationPage: 1,
        ordering: PageOrderEnum::Latest,
        pageType: 'page',
        withPagination: true,
        cacheKeyPrepend: 'large-rendering-stress',
        useCache: false,
    );

    assert($pages instanceof LengthAwarePaginator);

    expect($pages->count())->toBe(12)
        ->and($pages->total())->toBe($fixture['pageCount'])
        ->and($pages->pluck('id'))->toContain($fixture['targetPageId'])
        ->and($queryCount)->toBeLessThan(125);
});

/**
 * @return array{language: Language, pageCount: int, site: Site, targetPageId: int}
 */
function seedLargeFrontendRenderingDataset(int $pageCount): array
{
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    config()->set('capell-frontend.minify_html', true);
    config()->set('capell-frontend.render_html_content_with_blade', false);
    Cache::flush();

    $language = Language::factory()->createOne(['code' => 'en']);
    $layout = Layout::factory()->default()->create();
    $theme = Theme::factory()->createOne(['key' => 'large-stress-theme']);
    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'large-stress-theme',
            name: 'Large Stress Theme',
            description: 'Theme registry fixture for frontend stress rendering.',
            package: 'capell-app/frontend',
            previewImage: '',
            tags: [],
            bestFit: [],
            presets: [
                new ThemePresetData(
                    key: 'boardroom',
                    name: 'Boardroom',
                    description: 'Stress test preset.',
                    previewImage: '',
                ),
            ],
        ),
    );
    $type = Blueprint::factory()->page()->create([
        'key' => 'large-stress-pages',
        'meta' => ['content_structure' => 'html'],
    ]);

    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->withTranslations(
            languages: $language,
            data: ['title' => 'Large CMS site'],
            siteDomainData: [
                'default' => true,
                'domain' => 'large-capell.test',
                'scheme' => 'http',
                'path' => null,
            ],
        )
        ->create();

    $targetPageId = seedLargeFrontendRenderingPages(
        pageCount: $pageCount,
        language: $language,
        layout: $layout,
        site: $site,
        type: $type,
    );

    return [
        'language' => $language,
        'pageCount' => $pageCount,
        'site' => $site,
        'targetPageId' => $targetPageId,
    ];
}

function seedLargeFrontendRenderingPages(
    int $pageCount,
    Language $language,
    Layout $layout,
    Site $site,
    Blueprint $type,
): int {
    $pageRows = [];
    $translationRows = [];
    $urlRows = [];
    $pageMorphClass = (new Page)->getMorphClass();
    $publishedAt = CarbonImmutable::parse('2026-01-01 00:00:00');
    $targetPageNumber = $pageCount;
    $targetPageId = 0;

    foreach (range(1, $pageCount) as $pageNumber) {
        $pageId = 100000 + $pageNumber;
        $isTargetPage = $pageNumber === $targetPageNumber;
        $title = $isTargetPage
            ? 'Large CMS target page'
            : sprintf('Large CMS filler page %d', $pageNumber);

        if ($isTargetPage) {
            $targetPageId = $pageId;
        }

        $pageRows[] = [
            'id' => $pageId,
            'uuid' => null,
            'name' => $title,
            'blueprint_id' => $type->id,
            'layout_id' => $layout->id,
            'site_id' => $site->id,
            'meta' => null,
            'admin' => null,
            'visible_from' => $publishedAt,
            'visible_until' => null,
            'order' => $pageNumber,
            '_lft' => $pageNumber * 2 - 1,
            '_rgt' => $pageNumber * 2,
            'parent_id' => null,
            'depth' => 0,
            'created_at' => $publishedAt->addSeconds($pageNumber),
            'updated_at' => $publishedAt->addSeconds($pageNumber),
            'deleted_at' => null,
        ];

        $translationRows[] = [
            'language_id' => $language->id,
            'translatable_type' => $pageMorphClass,
            'translatable_id' => $pageId,
            'title' => $title,
            'content' => $isTargetPage
                ? '<p>Rendered from {{ page.translation.title }}</p><script>alert("target")</script>'
                : sprintf('<p>Filler body %d</p>', $pageNumber),
            'meta' => json_encode(
                ['slug' => $isTargetPage ? 'target-stress-page' : sprintf('stress-pages/%04d', $pageNumber)],
                JSON_THROW_ON_ERROR,
            ),
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ];

        $urlRows[] = [
            'site_id' => $site->id,
            'language_id' => $language->id,
            'pageable_type' => $pageMorphClass,
            'pageable_id' => $pageId,
            'url' => $isTargetPage ? '/target-stress-page' : sprintf('/stress-pages/%04d', $pageNumber),
            'target_url' => null,
            'status_code' => 301,
            'is_manual' => false,
            'notes' => null,
            'type' => null,
            'status' => true,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
            'deleted_at' => null,
        ];

        if (count($pageRows) === 250) {
            flushLargeFrontendRenderingRows($pageRows, $translationRows, $urlRows);
            $pageRows = [];
            $translationRows = [];
            $urlRows = [];
        }
    }

    flushLargeFrontendRenderingRows($pageRows, $translationRows, $urlRows);

    return $targetPageId;
}

/**
 * @param  array<int, array<string, mixed>>  $pageRows
 * @param  array<int, array<string, mixed>>  $translationRows
 * @param  array<int, array<string, mixed>>  $urlRows
 */
function flushLargeFrontendRenderingRows(array $pageRows, array $translationRows, array $urlRows): void
{
    if ($pageRows === []) {
        return;
    }

    DB::table('pages')->insert($pageRows);
    DB::table('translations')->insert($translationRows);
    DB::table('page_urls')->insert($urlRows);
}
