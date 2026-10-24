<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Concerns\HasCache;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\PrivateSiteSpecSourceStore;
use Capell\Frontend\Actions\BuildPublicPageRenderDataAction;
use Capell\Frontend\Actions\GenerateStaticPageArtifactsAction;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Jobs\FlushCdnPurgeBatchJob;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
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
