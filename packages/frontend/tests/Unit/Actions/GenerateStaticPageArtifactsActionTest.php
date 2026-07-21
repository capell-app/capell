<?php

declare(strict_types=1);

use Capell\Core\Contracts\SiteAccessPolicyProvider;
use Capell\Core\Data\SiteAccessContextData;
use Capell\Core\Data\SiteAccessPolicyData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteAccess\SiteAccessPolicyRegistry;
use Capell\Frontend\Actions\GenerateStaticPageArtifactsAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

afterEach(function (): void {
    File::deleteDirectory(resolve(StaticPageArtifactStore::class)->root());
    app()->forgetInstance(SiteAccessPolicyRegistry::class);
});

it('prohibits static generation when a site access provider protects the host', function (): void {
    [, $site] = staticPageArtifactsRenderData('/protected-static-test');
    resolve(SiteAccessPolicyRegistry::class)->register(new class implements SiteAccessPolicyProvider
    {
        public function key(): string
        {
            return 'protected-static-test';
        }

        public function resolve(SiteAccessContextData $context): ?SiteAccessPolicyData
        {
            return new SiteAccessPolicyData(active: true, methods: ['shared_password']);
        }
    });

    expect(fn (): array => GenerateStaticPageArtifactsAction::run(
        siteId: $site->id,
        urls: ['/protected-static-test'],
    ))->toThrow(RuntimeException::class, 'Static generation is prohibited for protected site host');
});

it('generates static html artifacts and writes a metadata manifest for published urls', function (): void {
    config()->set('cache.default', 'array');

    [$page, $site, $renderData] = staticPageArtifactsRenderData('/static-test');

    app()->instance(Kernel::class, new readonly class($renderData) implements Kernel
    {
        public function __construct(private PublicPageRenderData $renderData) {}

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            resolve(FrontendContextReader::class)->setFrontendData('publicPageRenderData', $this->renderData);
            $page = expectPresent($this->renderData->page);

            return new Response('<html>Static</html>', Response::HTTP_OK, ['surrogate-key' => 'page-' . $page->getKey()]);
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id, urls: ['/static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($manifest['artifacts'])->toHaveCount(1)
        ->and($manifest['artifacts'][0]['file'])->toBe('https.example.test/static-test/index.html')
        ->and($manifest['artifacts'][0]['surrogateKeys'])->toBe([])
        ->and($manifest['artifacts'][0]['headers'])->not->toHaveKey('surrogate-key')
        ->and($manifest['artifacts'][0]['dependencies'])->toHaveKey('fingerprint')
        ->and(File::get($store->root() . '/https.example.test/static-test/index.html'))->toBe('<html>Static</html>')
        ->and($store->readManifest()['artifacts'])->toHaveCount(1)
        ->and(json_encode($store->readManifest(), JSON_THROW_ON_ERROR))->not->toContain('page-' . $page->id)
        ->and(json_encode($store->readManifest(), JSON_THROW_ON_ERROR))->not->toContain('"id":' . $page->id);
});

it('generates static html artifacts for the default enabled site domain', function (): void {
    config()->set('cache.default', 'array');

    [, $site, $renderData] = staticPageArtifactsRenderData('/default-domain-static-test');
    $language = expectPresent($renderData->language);

    SiteDomain::factory()
        ->state([
            'site_id' => $site->id,
            'language_id' => $language->id,
            'scheme' => 'https',
            'domain' => 'preview.example.test',
            'path' => '/',
            'default' => false,
        ])
        ->createOne();

    app()->instance(Kernel::class, new readonly class($renderData) implements Kernel
    {
        public function __construct(private PublicPageRenderData $renderData) {}

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            resolve(FrontendContextReader::class)->setFrontendData('publicPageRenderData', $this->renderData);
            $page = expectPresent($this->renderData->page);

            return new Response('<html>Default domain</html>', Response::HTTP_OK, ['surrogate-key' => 'page-' . $page->getKey()]);
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id, urls: ['/default-domain-static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($manifest['artifacts'])->toHaveCount(1)
        ->and($manifest['artifacts'][0]['file'])->toBe('https.example.test/default-domain-static-test/index.html')
        ->and(File::exists($store->root() . '/https.preview.example.test/default-domain-static-test/index.html'))->toBeFalse();
});

it('does not write flagged public html safety responses to static artifacts', function (): void {
    config()->set('cache.default', 'array');

    [, $site, $renderData] = staticPageArtifactsRenderData('/unsafe-static-test');

    app()->instance(Kernel::class, new readonly class($renderData) implements Kernel
    {
        public function __construct(private PublicPageRenderData $renderData) {}

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            resolve(FrontendContextReader::class)->setFrontendData('publicPageRenderData', $this->renderData);

            return new Response(
                '<html><body data-model-id="42">Unsafe</body></html>',
                Response::HTTP_OK,
                ['X-Capell-Public-Html-Safety' => 'authoring_marker'],
            );
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id, urls: ['/unsafe-static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($manifest['artifacts'])->toBe([])
        ->and(File::exists($store->root() . '/https.example.test/unsafe-static-test/index.html'))->toBeFalse()
        ->and($store->readManifest()['artifacts'])->toBe([]);
});

it('inspects response bodies before writing static artifacts', function (): void {
    config()->set('cache.default', 'array');

    [, $site, $renderData] = staticPageArtifactsRenderData('/unguarded-unsafe-static-test');

    app()->instance(Kernel::class, new readonly class($renderData) implements Kernel
    {
        public function __construct(private PublicPageRenderData $renderData) {}

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            resolve(FrontendContextReader::class)->setFrontendData('publicPageRenderData', $this->renderData);

            return new Response('<html><body data-model-id="42">Unsafe</body></html>');
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id, urls: ['/unguarded-unsafe-static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($manifest['artifacts'])->toBe([])
        ->and(File::exists($store->root() . '/https.example.test/unguarded-unsafe-static-test/index.html'))->toBeFalse();
});

it('rejects signed admin urls before writing static artifacts', function (): void {
    config()->set('cache.default', 'array');

    [, $site, $renderData] = staticPageArtifactsRenderData('/signed-admin-url-static-test');

    app()->instance(Kernel::class, new readonly class($renderData) implements Kernel
    {
        public function __construct(private PublicPageRenderData $renderData) {}

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            resolve(FrontendContextReader::class)->setFrontendData('publicPageRenderData', $this->renderData);

            return new Response('<html><body><a href="/admin/pages/1/edit?signature=plain-signature">Edit</a></body></html>');
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id, urls: ['/signed-admin-url-static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($manifest['artifacts'])->toBe([])
        ->and(File::exists($store->root() . '/https.example.test/signed-admin-url-static-test/index.html'))->toBeFalse();
});

it('trusts html already inspected by the frontend renderer before writing static artifacts', function (): void {
    config()->set('cache.default', 'array');

    [, $site, $renderData] = staticPageArtifactsRenderData('/already-inspected-static-test');

    app()->instance(Kernel::class, new readonly class($renderData) implements Kernel
    {
        public function __construct(private PublicPageRenderData $renderData) {}

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            $html = '<html><body>Already inspected</body></html>';

            resolve(FrontendContextReader::class)->setFrontendData('publicPageRenderData', $this->renderData);
            resolve(FrontendContextReader::class)->setFrontendData('publicHtmlSafetyInspected', true);
            resolve(FrontendContextReader::class)->setFrontendData('publicHtmlSafetyInspectedHash', hash('xxh128', $html));

            return new Response($html);
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id, urls: ['/already-inspected-static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($manifest['artifacts'])->toHaveCount(1)
        ->and(File::get($store->root() . '/https.example.test/already-inspected-static-test/index.html'))->toBe('<html><body>Already inspected</body></html>');
});

it('re-inspects static html when inspected content changes after rendering', function (): void {
    config()->set('cache.default', 'array');

    [, $site, $renderData] = staticPageArtifactsRenderData('/mutated-after-inspection-static-test');

    app()->instance(Kernel::class, new readonly class($renderData) implements Kernel
    {
        public function __construct(private PublicPageRenderData $renderData) {}

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            resolve(FrontendContextReader::class)->setFrontendData('publicPageRenderData', $this->renderData);
            resolve(FrontendContextReader::class)->setFrontendData('publicHtmlSafetyInspected', true);
            resolve(FrontendContextReader::class)->setFrontendData('publicHtmlSafetyInspectedHash', hash('xxh128', '<html><body>Safe</body></html>'));

            return new Response('<html><body data-model-id="42">Mutated</body></html>');
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id, urls: ['/mutated-after-inspection-static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($manifest['artifacts'])->toBe([])
        ->and(File::exists($store->root() . '/https.example.test/mutated-after-inspection-static-test/index.html'))->toBeFalse();
});

it('builds public render data when the frontend renderer does not expose it', function (): void {
    config()->set('cache.default', 'array');

    [$page, $site] = staticPageArtifactsRenderData('/fallback-render-data-static-test');

    app()->instance(Kernel::class, new class implements Kernel
    {
        public function bootstrap(): void {}

        public function handle($request): Response
        {
            return new Response('<html><body>Fallback render data</body></html>');
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $manifest = GenerateStaticPageArtifactsAction::run(siteId: $site->id, urls: ['/fallback-render-data-static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($manifest['artifacts'])->toHaveCount(1)
        ->and($manifest['artifacts'][0]['dependencies'])->toHaveKey('fingerprint')
        ->and(json_encode($manifest, JSON_THROW_ON_ERROR))->not->toContain('"id":' . $page->id)
        ->and(File::get($store->root() . '/https.example.test/fallback-render-data-static-test/index.html'))
        ->toBe('<html><body>Fallback render data</body></html>');
});

it('skips urls without an enabled site domain or writable html response', function (): void {
    config()->set('cache.default', 'array');

    [, $siteWithoutDomain] = staticPageArtifactsRenderData('/missing-domain-static-test');
    SiteDomain::query()->where('site_id', $siteWithoutDomain->id)->delete();

    [, $siteWithJson, $renderData] = staticPageArtifactsRenderData('/json-static-test');

    app()->instance(Kernel::class, new readonly class($renderData) implements Kernel
    {
        public function __construct(private PublicPageRenderData $renderData) {}

        public function bootstrap(): void {}

        public function handle($request): IlluminateResponse
        {
            resolve(FrontendContextReader::class)->setFrontendData('publicPageRenderData', $this->renderData);

            return new IlluminateResponse('{"ok":true}', Response::HTTP_OK, ['content-type' => 'application/json']);
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    });

    $missingDomainManifest = GenerateStaticPageArtifactsAction::run(siteId: $siteWithoutDomain->id, urls: ['/missing-domain-static-test']);
    $jsonManifest = GenerateStaticPageArtifactsAction::run(siteId: $siteWithJson->id, urls: ['/json-static-test']);
    $store = resolve(StaticPageArtifactStore::class);

    expect($missingDomainManifest['artifacts'])->toBe([])
        ->and($jsonManifest['artifacts'])->toBe([])
        ->and(File::exists($store->root() . '/https.example.test/missing-domain-static-test/index.html'))->toBeFalse()
        ->and(File::exists($store->root() . '/https.example.test/json-static-test/index.html'))->toBeFalse();
});

/**
 * @return array{0: Page, 1: Site, 2: PublicPageRenderData}
 */
function staticPageArtifactsRenderData(string $url): array
{
    $page = Page::factory()
        ->withTranslations()
        ->createOne();
    $language = Language::query()->findOrFail((int) $page->translations->first()->language_id);
    $site = Site::query()->findOrFail((int) $page->site_id);
    SiteDomain::query()
        ->where('site_id', $site->id)
        ->where('language_id', $language->id)
        ->delete();
    SiteDomain::factory()
        ->state([
            'site_id' => $site->id,
            'language_id' => $language->id,
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => '/',
            'default' => true,
        ])
        ->createOne();
    PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->state(['url' => $url])
        ->createOne();
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $renderData = new PublicPageRenderData(
        page: $page,
        site: $site,
        language: $language,
        layout: Layout::query()->find($page->layout_id),
        theme: $site->theme,
        layoutGraph: null,
        runtimeManifest: $runtime,
        resourcePlan: new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty')),
        surrogateKeys: ['page-' . $page->id],
    );

    return [$page, $site, $renderData];
}
