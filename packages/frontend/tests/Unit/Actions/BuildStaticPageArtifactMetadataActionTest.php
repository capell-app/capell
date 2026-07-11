<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\BuildStaticPageArtifactMetadataAction;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Symfony\Component\HttpFoundation\Response;

it('builds deterministic static page artifact metadata from render data and response headers', function (): void {
    $page = Page::factory()
        ->withTranslations()
        ->create();
    $pageUrl = PageUrl::factory()->createOne([
        'site_id' => $page->site_id,
        'language_id' => $page->translations->first()->language_id,
        'pageable_type' => Page::class,
        'pageable_id' => $page->id,
        'url' => '/',
    ]);
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $assetManifest = new FrontendAssetManifestData(
        css: [
            new FrontendAssetRequirementData(
                handle: 'theme',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'resources/css/theme.css',
                buildPath: 'build',
            ),
        ],
        js: [],
        inline: [],
        preloads: [],
        runtime: $runtime,
    );
    $response = new Response('<html></html>', Response::HTTP_OK, [
        'content-type' => 'text/html; charset=UTF-8',
        'cache-control' => 'public, max-age=3600',
        'surrogate-key' => 'page-' . $page->id,
    ]);

    $metadata = BuildStaticPageArtifactMetadataAction::run(
        pageUrl: $pageUrl,
        renderData: new PublicPageRenderData(
            page: $page,
            site: $page->site()->firstOrFail(),
            language: Language::query()->findOrFail($pageUrl->language_id),
            layout: Layout::query()->find($page->layout_id),
            theme: Theme::query()->find($page->site()->value('theme_id')),
            layoutGraph: null,
            runtimeManifest: $runtime,
            assetManifest: $assetManifest,
            surrogateKeys: ['page-' . $page->id],
        ),
        response: $response,
        file: 'https.example.test/index.html',
    );

    $metadataPayload = json_encode($metadata->toArray(), JSON_THROW_ON_ERROR);

    expect($metadata->url)->toBe('/')
        ->and($metadata->file)->toBe('https.example.test/index.html')
        ->and($metadata->headers['cache-control'])->toBe('max-age=3600, public')
        ->and($metadata->headers)->not->toHaveKey('surrogate-key')
        ->and($metadata->dependencies)->toHaveKey('fingerprint')
        ->and($metadata->assets['css'])->toHaveKey('fingerprint')
        ->and($metadata->surrogateKeys)->toBe([])
        ->and($metadataPayload)->not->toContain(Page::class)
        ->and($metadataPayload)->not->toContain('"id":' . $page->id)
        ->and($metadataPayload)->not->toContain('"site_id":')
        ->and($metadataPayload)->not->toContain('"language_id":')
        ->and($metadataPayload)->not->toContain('"layout_id":')
        ->and($metadataPayload)->not->toContain('"theme_id":')
        ->and($metadataPayload)->not->toContain('resources/css/theme.css')
        ->and($metadataPayload)->not->toContain('build')
        ->and($metadataPayload)->not->toContain('page-' . $page->id);
});
