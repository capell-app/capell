<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Concerns\HasCache;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Events\SiteSpecPrivacyRepaired;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\PrivateSiteSpecSourceStore;
use Capell\Frontend\Actions\BuildPublicPageRenderDataAction;
use Capell\Frontend\Actions\GenerateStaticPageArtifactsAction;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PageListingSpec;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Jobs\FlushCdnPurgeBatchJob;
use Capell\Frontend\Support\Cache\CacheInvalidationDependencyRegistry;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\Cache\PageListingCache;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Capell\Frontend\Tests\Fixtures\FragmentCacheTestStore;
use Capell\Frontend\Tests\Fixtures\PrivacyRepairCacheStore;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('capell-frontend.public_render_data_cache', true);
    config()->set('capell-frontend.static_artifacts_path', storage_path('framework/testing/private-site-spec'));
});

afterEach(function (): void {
    $store = resolve(StaticPageArtifactStore::class);
    File::delete($store->ownershipPath());
    File::delete($store->ownershipPath() . '.lock');
    File::deleteDirectory($store->root());
});

function confidentialPrivateSiteSpec(): CapellSiteSpecData
{
    return CapellSiteSpecData::from([
        'site' => ['name' => 'Private import'],
        'theme' => ['key' => 'default'],
        'pages' => [[
            'name' => 'Home', 'slug' => 'home', 'title' => 'CONFIDENTIAL title', 'pageType' => 'default',
            'description' => 'CONFIDENTIAL launch on Friday',
            'meta' => ['title' => 'CONFIDENTIAL SEO title', 'robots' => 'index,follow', 'custom' => 'CONFIDENTIAL arbitrary metadata'],
            'sections' => [['type' => 'content', 'content' => '<p>CONFIDENTIAL content</p>']],
        ], [
            'name' => 'About', 'slug' => 'about', 'title' => 'CONFIDENTIAL secondary', 'pageType' => 'default', 'order' => 1,
            'meta' => ['robots' => 'index,follow'],
        ]],
    ]);
}

it('excludes authored placeholder metadata from rendered cached and static public output', function (): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    SiteDomain::factory()->create(['site_id' => $site->id, 'language_id' => $site->language_id, 'scheme' => 'https', 'domain' => 'private.test']);
    $home = $site->pages()->where('name', 'Home')->firstOrFail();
    $about = $site->pages()->where('name', 'About')->firstOrFail();
    $home->load(['translation', 'translations', 'pageUrls.siteDomain', 'layout']);
    $site->load(['translation', 'siteDomains', 'siteDomain', 'theme']);
    $context = new FrontendRenderContextData(
        page: $home,
        site: $site,
        language: $site->language,
        layout: $home->layout,
        theme: $site->theme,
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );
    $cache = resolve(PublicPageRenderDataCache::class);
    $cache->remember($context, fn (): PublicPageRenderData => BuildPublicPageRenderDataAction::run($context));

    $data = $cache->remember($context, function (): never {
        throw new RuntimeException('Expected a public render data cache hit.');
    });
    Frontend::swap(new FrontendContext(
        site: $data->site,
        language: $data->language,
        page: $data->page,
        layout: $data->layout,
        theme: $data->theme,
        params: ['resourcePlan' => $data->resourcePlan, 'runtimeManifest' => $data->runtimeManifest],
        slug: null,
    ));
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" :resource-plan="$resourcePlan" />', ['resourcePlan' => $data->resourcePlan]);
    expect($data->page?->getKey())->toBe($home->id);
    expect($html)->toContain('Coming soon', 'noindex', 'nofollow')->not->toContain('CONFIDENTIAL', 'index,follow')
        ->and(json_encode($data->page, JSON_THROW_ON_ERROR))->not->toContain('CONFIDENTIAL')
        ->and($about->translation->meta['robots'])->toBe('noindex,nofollow')
        ->and($about->meta['robots'])->toBe('noindex,nofollow');

    // Exercise export selection, rendering and artefact publication with the real public head.
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->once()->with(Mockery::type(Request::class))->andReturn(new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html']));
    $kernel->shouldReceive('terminate')->once();
    app()->instance(Kernel::class, $kernel);
    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id);
    expect($manifest['artifacts'])->toHaveCount(1);
    $file = resolve(StaticPageArtifactStore::class)->root() . '/' . $manifest['artifacts'][0]['file'];
    expect(File::get($file))->toContain('Coming soon', 'noindex')->not->toContain('CONFIDENTIAL');

    $source = resolve(PrivateSiteSpecSourceStore::class)->path($site->meta['site_spec_hash']);
    expect(File::get($source))->toContain('CONFIDENTIAL SEO title', 'CONFIDENTIAL arbitrary metadata')
        ->and(fileperms($source) & 0777)->toBe(0600);
    File::delete($source);
});

it('purges legacy private import caches and exports while retaining other sites', function (): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $home = $site->pages()->where('name', 'Home')->firstOrFail();
    $translation = $home->translations()->firstOrFail();
    $translation->update(['meta' => array_merge($translation->meta, ['description' => 'CONFIDENTIAL legacy metadata', 'robots' => 'index,follow'])]);

    $cache = new class
    {
        use HasCache;
    };
    $cache->setToCache('page-legacy-private', 'CONFIDENTIAL cached HTML');

    $privateManifest = exportPrivateRepairFixture($site, 'private.test', 'CONFIDENTIAL export');
    $other = Site::factory()->create();
    Page::factory()->withTranslations()->create(['site_id' => $other->id, 'visible_from' => null]);
    $otherManifest = exportPrivateRepairFixture($other, 'other.test', 'Other site');
    $store = resolve(StaticPageArtifactStore::class);
    $privateFile = $privateManifest['artifacts'][0]['file'];
    $otherFile = $otherManifest['artifacts'][0]['file'];
    expect($privateManifest['artifacts'][0]['dependencies'])->not->toHaveKey('site_id')
        ->and($store->readManifest())->toBe($otherManifest);

    BuildCapellSiteFromSpecAction::run($spec, true);
    expect($cache->getFromCache('page-legacy-private'))->toBeNull()
        ->and(File::exists($store->root() . '/' . $privateFile))->toBeFalse()
        ->and(File::get($store->root() . '/' . $otherFile))->toBe('<html>Other site</html>')
        ->and($store->readManifest()['artifacts'])->toHaveCount(1)
        ->and($translation->fresh()->meta_description)->not->toContain('CONFIDENTIAL')
        ->and($translation->fresh()->meta['robots'])->toBe('noindex,nofollow');
});

it('requires acknowledged Core cache invalidation before certifying repair and abandons retained private entries on retry', function (string $failure, bool $tags): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $home = $site->pages()->where('name', 'Home')->firstOrFail();
    $about = $site->pages()->where('name', 'About')->firstOrFail();
    $translation = $home->translations()->firstOrFail();
    $translation->update(['meta' => array_merge($translation->meta, ['description' => 'CONFIDENTIAL legacy metadata'])]);

    $about->update(['visible_from' => null]);

    $store = new PrivacyRepairCacheStore(true);
    $repository = new class($store) extends Repository
    {
        public bool $tags = true;

        #[Override]
        public function supportsTags(): bool
        {
            return $this->tags;
        }
    };
    $repository->tags = $tags;
    Cache::extend('privacy-repair', fn (): Repository => $repository);
    config()->set('cache.stores.privacy-repair', ['driver' => 'privacy-repair']);
    config()->set('cache.default', 'privacy-repair');
    freshPrivacyRepairCacheManager();
    $models = resolve(PageModelCache::class);
    $listings = resolve(PageListingCache::class);
    $listing = new PageListingSpec($site->language_id, $site->id, null, null, null, null, null, null, null, null, false, true, null, 'privacy-repair');
    $loadIds = fn (): array => $site->pages()->publishedDate()->orderBy('id')->pluck('id')->all();
    $legacyIds = $listings->getIds($listing, $loadIds);
    expect($legacyIds)->toContain($about->id)
        ->and($models->get(Page::class, $home->id, $site, $site->language)?->translation?->meta_description)->toContain('CONFIDENTIAL');

    $home->load(['translation', 'translations', 'pageUrls.siteDomain', 'layout']);
    $site->load(['translation', 'siteDomains', 'siteDomain', 'theme']);
    $context = new FrontendRenderContextData(
        page: $home,
        site: $site,
        language: $site->language,
        layout: $home->layout,
        theme: $site->theme,
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );
    $renderData = resolve(PublicPageRenderDataCache::class);
    expect(serialize($renderData->remember($context, fn (): PublicPageRenderData => BuildPublicPageRenderDataAction::run($context))))->toContain('CONFIDENTIAL');
    $legacyEntries = array_filter($store->entries(), static fn (mixed $value): bool => $value instanceof Page
        || $value instanceof PublicPageRenderData || $value === $legacyIds);
    expect($legacyEntries)->toHaveCount(3);

    $store->generationFailure = $failure;
    expect(fn (): Site => BuildCapellSiteFromSpecAction::run($spec, true))->toThrow($failure === 'lock' ? LockTimeoutException::class : RuntimeException::class)
        ->and($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull()
        ->and(PublishSentinel::isDraftValue($about->fresh()->visible_from))->toBeTrue();

    $store->generationFailure = null;
    BuildCapellSiteFromSpecAction::run($spec, true);
    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2);

    // Retained backend entries must be unreachable to a fresh request/manager,
    // including readers with exactly the same registered wildcard patterns.
    foreach ($legacyEntries as $key => $value) {
        expect(serialize($store->get($key)))->toBe(serialize($value));
    }

    freshPrivacyRepairCacheManager();
    $repairedHome = $models->get(Page::class, $home->id, $site, $site->language);
    expect($models->get(Page::class, $about->id, $site, $site->language))->toBeNull()
        ->and($repairedHome)->toBeInstanceOf(Page::class)
        ->and($repairedHome?->translation?->meta_description)->not->toContain('CONFIDENTIAL')
        ->and($listings->getIds($listing, $loadIds))->not->toContain($about->id);

    // Reuse the original context/key inputs: timestamp changes must not mask a
    // retained render-data cache hit. A cache miss must execute this builder.
    $rebuilt = false;
    $safeData = $renderData->remember($context, function () use (&$rebuilt, $context, $home): PublicPageRenderData {
        $rebuilt = true;

        return BuildPublicPageRenderDataAction::run(new FrontendRenderContextData(
            page: $home->fresh(['translation', 'translations', 'pageUrls.siteDomain', 'layout']),
            site: $context->site,
            language: $context->language,
            layout: $context->layout,
            theme: $context->theme,
            runtimeManifest: $context->runtimeManifest,
        ));
    });
    expect($rebuilt)->toBeTrue()
        ->and(serialize($safeData))->not->toContain('CONFIDENTIAL');
})->with(['return-false', 'throw', 'unacknowledged-increment', 'unacknowledged-write', 'throw-write', 'lock', 'read'])->with([true, false]);

it('fences a Core cache fill that started before repair and writes after its completion', function (): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $manager = resolve(CapellCacheManager::class);

    $manager->rememberCache('page-in-flight', function () use ($spec): string {
        BuildCapellSiteFromSpecAction::run($spec, true);

        return 'CONFIDENTIAL late cache fill';
    });

    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2);
    freshPrivacyRepairCacheManager();
    expect(resolve(CapellCacheManager::class)->rememberCache('page-in-flight', static fn (): string => 'safe'))->toBe('safe');
});

function freshPrivacyRepairCacheManager(): void
{
    app()->instance('request', Request::create((string) config('app.url')));
    $manager = new CapellCacheManager;
    foreach (resolve(CacheInvalidationDependencyRegistry::class)->allPatterns() as $pattern) {
        $manager->registerCacheInvalidationPattern($pattern);
    }

    app()->instance(CapellCacheManager::class, $manager);
}

it('leaves a failed export remediation retryable without certifying the legacy import', function (bool $legacy): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $about = $site->pages()->where('name', 'About')->firstOrFail();
    $about->update(['visible_from' => null]);

    $manifest = exportPrivateRepairFixture($site, 'private.test', 'CONFIDENTIAL export');
    $store = resolve(StaticPageArtifactStore::class);
    $privateFile = $manifest['artifacts'][0]['file'];
    if ($legacy) {
        File::delete($store->ownershipPath());
    }

    File::partialMock()->shouldReceive('delete')->atLeast()->once()->with($store->root() . '/' . $privateFile)->andReturn(false);

    expect(fn (): Site => BuildCapellSiteFromSpecAction::run($spec, true))->toThrow(RuntimeException::class, 'could not be removed')
        ->and($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull()
        ->and(PublishSentinel::isDraftValue($about->fresh()->visible_from))->toBeTrue();

    // Stop simulating failed deletions before retrying against the real filesystem.
    File::clearResolvedInstance('files');
    app()->forgetInstance('files');
    BuildCapellSiteFromSpecAction::run($spec, true);
    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2)
        ->and(File::exists($store->root() . '/' . $privateFile))->toBeFalse();
})->with([false, true]);

it('requires acknowledged CDN delivery before certifying a repair and retries failed delivery with no worker', function (): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $about = $site->pages()->where('name', 'About')->firstOrFail();
    $about->update(['visible_from' => null]);

    $manifest = exportPrivateRepairFixture($site, 'private.test', 'CONFIDENTIAL export');
    $privateFile = resolve(StaticPageArtifactStore::class)->root() . '/' . $manifest['artifacts'][0]['file'];
    config()->set([
        'queue.default' => 'database',
        'capell-frontend.cdn_provider' => 'cloudflare',
        'capell-frontend.cloudflare_purge_token' => 'test-token',
        'capell-frontend.cloudflare_zone_id' => 'test-zone',
    ]);
    // No delayed job is processed: repair must send its own purge and observe
    // the failure, even though ordinary model invalidations are buffered.
    Bus::fake([FlushCdnPurgeBatchJob::class]);
    $responses = Http::sequence()
        ->push(['success' => false], 503)
        ->push(['success' => false], 200)
        ->push(['success' => true], 200);
    Http::fake(function (ClientRequest $request) use ($responses, $site, $about, $privateFile): PromiseInterface {
        // Publication changes and local deletion must precede the remote purge;
        // its acknowledgement must precede the durable completion marker.
        expect($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull()
            ->and(PublishSentinel::isDraftValue($about->fresh()->visible_from))->toBeTrue()
            ->and(File::exists($privateFile))->toBeFalse();

        return $responses($request);
    });

    expect(fn (): Site => BuildCapellSiteFromSpecAction::run($spec, true))->toThrow(RequestException::class)
        ->and($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull()
        ->and(PublishSentinel::isDraftValue($about->fresh()->visible_from))->toBeTrue()
        ->and(File::exists($privateFile))->toBeFalse();
    Http::assertSentCount(1);

    expect(fn (): Site => BuildCapellSiteFromSpecAction::run($spec, true))->toThrow(LogicException::class, 'did not acknowledge')
        ->and($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull();
    Http::assertSentCount(2);

    BuildCapellSiteFromSpecAction::run($spec, true);
    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2);
    Http::assertSentCount(3);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/zones/test-zone/purge_cache'
        && $request['tags'] === ['site-' . $site->id]);

    BuildCapellSiteFromSpecAction::run($spec, true);
    Http::assertSentCount(3);
});

it('awaits synchronous package privacy listeners after frontend cleanup and retries before recording completion', function (): void {
    config()->set('app.url', 'https://original.private.test');
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $manifest = exportPrivateRepairFixture($site, 'private.test', 'CONFIDENTIAL export');
    SiteDomain::factory()->create(['site_id' => $site->id, 'language_id' => $site->language_id, 'domain' => 'alias.private.test']);
    $privateFile = resolve(StaticPageArtifactStore::class)->root() . '/' . $manifest['artifacts'][0]['file'];
    $fragments = resolve(FragmentCache::class);
    $fragments->remember('package-repair', static fn (): string => 'CONFIDENTIAL fragment');

    config()->set([
        'queue.default' => 'database',
        'capell-frontend.cdn_provider' => 'cloudflare',
        'capell-frontend.cloudflare_purge_token' => 'test-token',
        'capell-frontend.cloudflare_zone_id' => 'test-zone',
    ]);
    Bus::fake([FlushCdnPurgeBatchJob::class]);
    Http::fake(['*' => Http::response(['success' => true])]);
    $listener = new class
    {
        public bool $fail = true;

        public int $successfulCalls = 0;

        public int $attempts = 0;
    };
    Event::listen(SiteSpecPrivacyRepaired::class, function (SiteSpecPrivacyRepaired $event) use ($site, $privateFile, $fragments, $listener): void {
        $listener->successfulCalls++;
        expect($event->data->siteIds)->toBe([$site->id])
            ->and($event->data->domains)->toEqualCanonicalizing(['original.private.test', 'private.test', 'alias.private.test'])
            ->and($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull()
            ->and(File::exists($privateFile))->toBeFalse()
            ->and($fragments->remember('package-repair', static fn (): string => 'safe'))->toBe('safe');
        Http::assertSentCount($listener->successfulCalls);
    });
    Event::listen(SiteSpecPrivacyRepaired::class, function () use ($listener): void {
        $listener->attempts++;
        throw_if($listener->fail, RuntimeException::class, 'Package privacy purge failed.');
    });

    expect(fn (): Site => BuildCapellSiteFromSpecAction::run($spec, true))->toThrow(RuntimeException::class, 'Package privacy purge failed.')
        ->and($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull()
        ->and($listener->successfulCalls)->toBe(1)
        ->and($listener->attempts)->toBe(1);

    $listener->fail = false;
    BuildCapellSiteFromSpecAction::run($spec, true);

    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2)
        ->and($listener->successfulCalls)->toBe(2)
        ->and($listener->attempts)->toBe(2);

    BuildCapellSiteFromSpecAction::run($spec, true);
    expect($listener->successfulCalls)->toBe(2)
        ->and($listener->attempts)->toBe(2);
    Http::assertSentCount(2);
});

it('discovers legacy confidential files omitted from the latest real export receipt', function (): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $private = exportPrivateRepairFixture($site, 'legacy-private.test', 'CONFIDENTIAL legacy export');
    $store = resolve(StaticPageArtifactStore::class);
    // Simulate an installation exported before private ownership tracking existed.
    File::delete($store->ownershipPath());
    $other = Site::factory()->create();
    Page::factory()->withTranslations()->create(['site_id' => $other->id, 'visible_from' => null]);
    $current = exportPrivateRepairFixture($other, 'current.test', 'Current public export');
    expect($store->readManifest())->toBe($current);

    BuildCapellSiteFromSpecAction::run($spec, true);

    expect(File::exists($store->root() . '/' . $private['artifacts'][0]['file']))->toBeFalse()
        ->and(File::get($store->root() . '/' . $current['artifacts'][0]['file']))->toBe('<html>Current public export</html>')
        ->and($site->fresh()->meta['site_spec_privacy_version'])->toBe(2);
});

it('makes confidential fragments unreachable despite failed deletion or surrogate locks', function (string $failure): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $store = new FragmentCacheTestStore;
    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'legacy', 3600);

    $fragments = new FragmentCache($repository);
    app()->instance(FragmentCache::class, $fragments);
    $fragments->remember('private', static fn (): string => 'CONFIDENTIAL fragment', surrogateKeys: ['site-' . $site->id]);
    $fragments->remember('untagged', static fn (): string => 'CONFIDENTIAL untagged');

    $legacyKeys = $repository->get('fragment:legacy:surrogate:map')['site-' . $site->id];

    expect($fragments->remember('private', static fn (): string => 'unexpected'))->toBe('CONFIDENTIAL fragment');
    $store->fragmentDeletionFailure = $failure === 'lock' ? null : $failure;
    $store->failLocks = $failure === 'lock';

    BuildCapellSiteFromSpecAction::run($spec, true);

    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2)
        // The retained bytes are deliberately inaccessible through every reader,
        // including fragments without surrogate membership.
        ->and($repository->get($legacyKeys[0]))->toBe('CONFIDENTIAL fragment')
        ->and($fragments->remember('private', static fn (): string => 'safe'))->toBe('safe')
        ->and($fragments->remember('untagged', static fn (): string => 'safe untagged'))->toBe('safe untagged');
})->with(['return-false', 'throw', 'lock']);

it('does not certify repair until fragment namespace rotation is acknowledged and can retry', function (string $failure): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $store = new FragmentCacheTestStore;
    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'legacy', 3600);

    $fragments = new FragmentCache($repository);
    app()->instance(FragmentCache::class, $fragments);
    $fragments->remember('private', static fn (): string => 'CONFIDENTIAL fragment', surrogateKeys: ['site-' . $site->id]);
    $store->namespaceWriteFailure = $failure;

    expect(fn (): Site => BuildCapellSiteFromSpecAction::run($spec, true))->toThrow(RuntimeException::class)
        ->and($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull()
        ->and($repository->get('fragment:namespace'))->toBe('legacy');

    $store->namespaceWriteFailure = null;
    BuildCapellSiteFromSpecAction::run($spec, true);

    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2)
        ->and($fragments->remember('private', static fn (): string => 'safe'))->toBe('safe');
})->with(['return-false', 'throw', 'unacknowledged']);

it('cannot republish a pre-repair fragment into the active namespace after repair completes', function (): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $store = new FragmentCacheTestStore;
    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'legacy', 3600);

    $fragments = new FragmentCache($repository);
    app()->instance(FragmentCache::class, $fragments);
    $store->beforeMapWrite = static function () use ($spec): void {
        BuildCapellSiteFromSpecAction::run($spec, true);
    };

    $fragments->remember('in-flight', static fn (): string => 'CONFIDENTIAL in flight', surrogateKeys: ['site-' . $site->id]);

    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2)
        ->and($fragments->remember('in-flight', static fn (): string => 'safe'))->toBe('safe');
});

/** @return array<string, mixed> */
function exportPrivateRepairFixture(Site $site, string $host, string $html): array
{
    SiteDomain::factory()->create(['site_id' => $site->id, 'language_id' => $site->language_id, 'scheme' => 'https', 'domain' => $host]);
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->andReturn(new Response('<html>' . $html . '</html>', Response::HTTP_OK, ['Content-Type' => 'text/html']));
    $kernel->shouldReceive('terminate');
    app()->instance(Kernel::class, $kernel);

    return GenerateStaticPageArtifactsAction::run(siteId: $site->id);
}
