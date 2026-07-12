<?php

declare(strict_types=1);

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Enums\CacheTime;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\GenerateAllMaintenancePageCachesAction;
use Capell\Frontend\Actions\PurgeCdnCacheByPageAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Http\Middleware\PreventAuthenticatedFrontendRenderingWhenHtmlCacheable;
use Capell\Frontend\Jobs\PurgeCdnCacheJob;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\CapellFrontendContext;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Support\Rules\Conditions\CampaignParameterCondition;
use Capell\Frontend\Support\Rules\Conditions\QueryParameterCondition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\Response;

it('builds stable frontend cache keys for page, site, navigation, and render data workflows', function (): void {
    $listingKey = CacheEnum::pages(2, 10, 12, [
        'pageable_type' => 'article',
        'pageable_id' => 55,
        'type' => 'news',
        'with_image' => true,
        'with_parent' => true,
        'with_date' => true,
        'with_child_count' => true,
        'with_children' => true,
        'only_listable_types' => '1',
        'page_type' => 'post',
        'page_group' => 'marketing',
        'type_key' => 'case-study',
        'ordering' => 'latest',
        'with_pagination' => true,
        'pagination_key' => 'p',
        'pagination_page' => 3,
        'cache_key_prepend' => 'homepage',
    ]);

    expect(CacheEnum::custom('hero'))->toBe('frontend.hero')
        ->and(CacheEnum::custom('hero', 'site'))->toBe('site.hero')
        ->and(CacheEnum::site(10, 2))->toBe('site-10-2')
        ->and(CacheEnum::siteMedia(10, 2))->toBe('site-media-10-language-2')
        ->and(CacheEnum::sitePage('children', 10, 2))->toBe('site-children-page-10-language-2')
        ->and(CacheEnum::pageLanguages(99, 2))->toBe('page-languages-99-language-2')
        ->and(CacheEnum::siteRelated(10, 2))->toBe('site-related-10-language-2')
        ->and(CacheEnum::pageCanonicals(99, 2))->toBe('page-canonicals-99-2')
        ->and(CacheEnum::pageError(10, 2))->toBe('system-page-error-10-2')
        ->and(CacheEnum::systemPage('maintenance', 10, 2))->toBe('system-page-maintenance-10-2')
        ->and(CacheEnum::homePage(10, 2))->toBe('homepage-10-2')
        ->and(CacheEnum::pageNext('post', 99, 10, 2))->toBe('page-next-post-99-site-10-lang-2')
        ->and(CacheEnum::pagePrevious('post', 99, 10, 2))->toBe('page-previous-post-99-site-10-lang-2')
        ->and(CacheEnum::pageAncestors(99, 10, 2))->toBe('page-ancestors-99-site-10-language-2')
        ->and(CacheEnum::pageByUrl('about', 10, 2, 'page', 99))->toBe('page-url-about-site-10-lang-2-page-page-99')
        ->and(CacheEnum::publicRenderData('page', 99, 10, 2, 'blade', 'v1'))->toBe('public-render-data-page-99-site-10-lang-2-strategy-blade-version-v1')
        ->and(CacheEnum::publicRenderDataGeneration('page', 99, 10, 2))->toBe('public-render-data-generation-page-99-site-10-lang-2')
        ->and(CacheEnum::pageMedia(99))->toBe('page-media-99')
        ->and($listingKey)->toContain('pages-2-10-limit-12')
        ->and($listingKey)->toContain('-page-article-55')
        ->and($listingKey)->toContain('-type-news')
        ->and($listingKey)->toContain('-image-parent-published-child-count-children-listable')
        ->and($listingKey)->toContain('-page-type-post-page-group-marketing-page-type-key-case-study')
        ->and($listingKey)->toContain('-ordering-latest-p-3-homepage')
        ->and(CacheEnum::urlById('page', 'uuid-1', 10, 2))->toBe('page-url-page-uuid-1-site-10-lang-2')
        ->and(CacheEnum::loadPage('parent', 99, 10, 2))->toBe('page-relations-parent-page-99-site-10-lang-2')
        ->and(CacheEnum::loadPage('parent', 99, 10, 2, 'custom'))->toBe('custom-parent-page-99-site-10-lang-2')
        ->and(CacheEnum::siteNavigations(10))->toBe('site-navigations-10')
        ->and(CacheEnum::navigation('main', 10))->toBe('navigation-main-site-10')
        ->and(CacheEnum::navigation('main', 10, 2))->toBe('navigation-main-site-10-language-2')
        ->and(CacheEnum::navigationById(5))->toBe('navigation-5')
        ->and(CacheEnum::media(7))->toBe('media-7')
        ->and(CacheEnum::pageIds('listing-key', 4))->toBe('listing-key-gen-4')
        ->and(CacheEnum::pageModel(Page::class, 99, 10, 2))->toBe('page-model-Page-99-site-10-lang-2')
        ->and(CacheEnum::listingGeneration(10, 2))->toBe('listing-gen-10-2');
});

it('lets packages compose frontend route middleware without duplicates', function (): void {
    $registry = new FrontendRouteMiddlewareRegistry;

    $registry
        ->prepend(['tenant.context', 'web'])
        ->append(['frontend.metrics', 'web'])
        ->insertBefore('frontend.resolve', ['theme.preview', 'tenant.context'])
        ->insertAfter('frontend.resolve', ['frontend.assets', 'frontend.metrics'])
        ->insertBefore('missing.middleware', ['fallback.before'])
        ->insertAfter('also.missing', ['fallback.after']);

    $middleware = $registry->all();
    $lastMiddlewareKey = array_key_last($middleware);
    assert(is_int($lastMiddlewareKey));

    expect($middleware[0])->toBe('fallback.before')
        ->and($middleware)->toContain('tenant.context')
        ->and(array_count_values($middleware)['web'])->toBe(1)
        ->and(array_count_values($middleware)['tenant.context'])->toBe(1)
        ->and(array_search('theme.preview', $middleware, true))->toBeLessThan(array_search('frontend.resolve', $middleware, true))
        ->and(array_search('frontend.assets', $middleware, true))->toBeGreaterThan(array_search('frontend.resolve', $middleware, true))
        ->and($middleware[$lastMiddlewareKey])->toBe('fallback.after');
});

it('runs the static HTML generator command with selected site and URL filters', function (): void {
    artisanCommand('capell:generate-html', [
        '--site' => 'not-numeric',
        '--url' => ['/missing-page', 42, '/also-missing'],
    ])
        ->expectsOutputToContain('generate static Capell HTML')
        ->expectsOutputToContain('Generated 0 static page artifact(s).')
        ->assertExitCode(Command::SUCCESS);
});

it('purges CDN and fragment caches for page surrogate keys', function (): void {
    config(['capell-frontend.cdn_provider' => 'fastly']);

    Bus::fake([PurgeCdnCacheJob::class]);

    $language = Language::factory()->english()->createOne();
    $theme = Theme::factory()->createOne(['default' => true]);
    $site = Site::factory()->withTranslations($language)->theme($theme)->createOne([
        'language_id' => $language->getKey(),
    ]);
    $layout = Layout::factory()->site($site)->createOne(['default' => true]);
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Cached page'])
        ->createOne();

    resolve(FragmentCache::class)->remember(
        'page-fragment',
        static fn (): string => 'cached fragment',
        surrogateKeys: ['page-' . $page->getKey(), 'site-' . $site->getKey(), 'lang-' . $language->code],
    );

    expect(Cache::has('fragment:page-fragment'))->toBeTrue();

    PurgeCdnCacheByPageAction::run($page);

    expect(Cache::has('fragment:page-fragment'))->toBeFalse();

    Bus::assertDispatched(PurgeCdnCacheJob::class, fn (PurgeCdnCacheJob $job): bool => $job->queue === config('capell-frontend.purge_queue', 'default'));
});

it('skips CDN purge jobs without skipping fragment invalidation when no provider is configured', function (): void {
    config(['capell-frontend.cdn_provider' => null]);

    Bus::fake([PurgeCdnCacheJob::class]);

    $language = Language::factory()->english()->createOne();
    $theme = Theme::factory()->createOne(['default' => true]);
    $site = Site::factory()->withTranslations($language)->theme($theme)->createOne([
        'language_id' => $language->getKey(),
    ]);
    $layout = Layout::factory()->site($site)->createOne(['default' => true]);
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Cached page'])
        ->createOne();

    resolve(FragmentCache::class)->remember(
        'page-fragment',
        static fn (): string => 'cached fragment',
        surrogateKeys: ['page-' . $page->getKey(), 'site-' . $site->getKey(), 'lang-' . $language->code],
    );

    expect(Cache::has('fragment:page-fragment'))->toBeTrue();

    PurgeCdnCacheByPageAction::run($page);

    expect(Cache::has('fragment:page-fragment'))->toBeFalse();

    Bus::assertNotDispatched(PurgeCdnCacheJob::class);
});

it('generates maintenance caches only for enabled sites in display order', function (): void {
    $store = new class implements StaticMaintenancePageStore
    {
        /** @var array<string, string> */
        public array $files = [];

        public function exists(string $file): bool
        {
            return array_key_exists($file, $this->files);
        }

        public function path(string $file): ?string
        {
            return $this->exists($file) ? storage_path('framework/testing/' . str_replace('/', '-', $file)) : null;
        }

        public function put(string $file, string $contents): void
        {
            $this->files[$file] = $contents;
        }
    };
    app()->instance(StaticMaintenancePageStore::class, $store);
    app()->instance(ThemePreviewRendererInterface::class, new class implements ThemePreviewRendererInterface
    {
        public function render(
            Theme $theme,
            Site $site,
            Page $page,
            ?Language $language = null,
            ?SiteDomain $siteDomain = null,
        ): Response {
            return new Response('<h1>Maintenance for ' . e($site->name) . '</h1>');
        }
    });

    $english = Language::factory()->english()->createOne();
    $theme = Theme::factory()->createOne(['default' => true]);
    $firstSite = Site::factory()
        ->withTranslations($english)
        ->theme($theme)
        ->createOne(['language_id' => $english->getKey(), 'name' => 'Alpha', 'status' => true]);
    $secondSite = Site::factory()
        ->withTranslations($english)
        ->theme($theme)
        ->createOne(['language_id' => $english->getKey(), 'name' => 'Beta', 'status' => true]);
    Site::factory()
        ->withTranslations($english)
        ->theme($theme)
        ->createOne(['language_id' => $english->getKey(), 'name' => 'Draft', 'status' => false]);

    $total = GenerateAllMaintenancePageCachesAction::run();

    expect($total)->toBe(2)
        ->and($store->files)->toHaveCount(2)
        ->and(implode("\n", $store->files))->toContain($firstSite->name)
        ->and(implode("\n", $store->files))->toContain($secondSite->name);
});

it('only allows known campaign parameters through the query parameter rule', function (): void {
    $condition = new CampaignParameterCondition(new QueryParameterCondition);
    $context = new FrontendRuleContextData(Request::create('/landing?utm_source=newsletter&utm_medium=email'));

    expect($condition->key())->toBe('campaign_parameter')
        ->and($condition->evaluate(['name' => 'utm_source', 'value' => 'newsletter'], $context))->toBeTrue()
        ->and($condition->evaluate(['key' => 'utm_medium', 'values' => ['email']], $context))->toBeTrue()
        ->and($condition->evaluate(['name' => 'ref', 'value' => 'newsletter'], $context))->toBeFalse()
        ->and($condition->evaluate(['name' => 'utm_campaign', 'value' => 'spring'], $context))->toBeFalse()
        ->and($condition->evaluate(['name' => 42], $context))->toBeFalse();
});

it('renders cacheable frontend GET requests anonymously even when Laravel has a user resolver', function (): void {
    config()->set('capell-frontend.html_cache', true);

    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->withTranslations($language)->createOne(['language_id' => $language->getKey()]);
    $type = Blueprint::factory()->page()->meta(['cache_time' => CacheTime::Daily->value])->createOne();
    $page = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->published()
        ->withTranslations($language, ['title' => 'Cacheable'])
        ->createOne()
        ->fresh(['blueprint']);
    expect($page)->toBeInstanceOf(Page::class);

    $reader = new class($page) implements FrontendContextReader
    {
        /** @var array<string, mixed> */
        private array $data = [];

        public function __construct(private readonly Page $page) {}

        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): Page
        {
            return $this->page;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            $this->data[$key] = $value;

            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return $key === null ? $this->data : ($this->data[$key] ?? null);
        }
    };
    Frontend::swap(new CapellFrontendContext($reader));

    $request = Request::create('/cacheable', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->setUserResolver(fn (): object => (object) ['id' => 1]);

    $response = resolve(PreventAuthenticatedFrontendRenderingWhenHtmlCacheable::class)->handle(
        $request,
        function (Request $request): Response {
            expect($request->user())->toBeNull();

            return new Response('public html');
        },
    );

    expect($response->getContent())->toBe('public html');
});
