<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Concerns\HasCache;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
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
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('capell-frontend.public_render_data_cache', true);
    config()->set('capell-frontend.static_artifacts_path', storage_path('framework/testing/private-site-spec'));
});

afterEach(function (): void {
    File::deleteDirectory(resolve(StaticPageArtifactStore::class)->root());
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

    $store = resolve(StaticPageArtifactStore::class);
    $store->putHtml('private/index.html', 'CONFIDENTIAL export');
    $store->putHtml('other/index.html', 'Other site');
    $store->writeManifest(['artifacts' => [
        ['file' => 'private/index.html', 'dependencies' => ['site_id' => $site->id]],
        ['file' => 'other/index.html', 'dependencies' => ['site_id' => $site->id + 1]],
    ]]);

    BuildCapellSiteFromSpecAction::run($spec, true);
    expect($cache->getFromCache('page-legacy-private'))->toBeNull()
        ->and(File::exists($store->root() . '/private/index.html'))->toBeFalse()
        ->and(File::get($store->root() . '/other/index.html'))->toBe('Other site')
        ->and($store->readManifest()['artifacts'])->toHaveCount(1)
        ->and($translation->fresh()->meta_description)->not->toContain('CONFIDENTIAL')
        ->and($translation->fresh()->meta['robots'])->toBe('noindex,nofollow');
});

it('leaves a failed export remediation retryable without certifying the legacy import', function (): void {
    $spec = confidentialPrivateSiteSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $meta = $site->meta;
    unset($meta['site_spec_privacy_version']);
    $site->update(['meta' => $meta]);
    $about = $site->pages()->where('name', 'About')->firstOrFail();
    $about->update(['visible_from' => null]);

    $store = resolve(StaticPageArtifactStore::class);
    $store->putHtml('private/index.html', 'CONFIDENTIAL export');
    $store->writeManifest(['artifacts' => [
        ['file' => 'private/index.html', 'dependencies' => ['site_id' => $site->id]],
    ]]);
    File::partialMock()->shouldReceive('delete')->once()->with($store->root() . '/private/index.html')->andReturn(false);

    expect(fn (): Site => BuildCapellSiteFromSpecAction::run($spec, true))->toThrow(RuntimeException::class, 'could not be removed')
        ->and($site->fresh()->meta['site_spec_privacy_version'] ?? null)->toBeNull()
        ->and(PublishSentinel::isDraftValue($about->fresh()->visible_from))->toBeTrue();

    // The failed delete expectation is exhausted; subsequent calls use the real filesystem.
    File::clearResolvedInstance('files');
    app()->forgetInstance('files');
    BuildCapellSiteFromSpecAction::run($spec, true);
    expect($site->fresh()->meta['site_spec_privacy_version'])->toBe(2)
        ->and(File::exists($store->root() . '/private/index.html'))->toBeFalse();
});
