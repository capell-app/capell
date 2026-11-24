<?php

declare(strict_types=1);

use Capell\Core\Data\Rendering\FrontendRuntimeManifestData;
use Capell\Core\Enums\Rendering\RenderingStrategyEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Facades\Frontend;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $siteMeta
 * @param  array<string, mixed>  $pageMeta
 */
function renderAiDisclosureHead(array $siteMeta, array $pageMeta): array
{
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()->language($language)->theme($theme)
        ->withTranslations($language, ['title' => 'Example Site'])
        ->create(['meta' => $siteMeta]);
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)
        ->withTranslations($language, ['title' => 'Example Page'])
        ->create(['meta' => $pageMeta]);

    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);

    $resourcePlan = new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty'));

    Frontend::swap(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'resourcePlan' => $resourcePlan,
            'runtimeManifest' => FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
        ],
        slug: null,
    ));

    DB::enableQueryLog();
    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" :resource-plan="$resourcePlan" />', ['resourcePlan' => $resourcePlan]);
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'capell_ai_provenance'))->count();
    DB::disableQueryLog();

    return [$html, $queries];
}

it('marks an AI-written page when the site turns disclosure on, without querying provenance', function (): void {
    [$html, $provenanceQueries] = renderAiDisclosureHead(['ai_disclosure' => 'marker'], ['ai_disclosure' => 'generated']);

    expect($html)->toContain('name="ai-disclosure"')
        ->and($html)->toContain('content="generated"')
        ->and($provenanceQueries)->toBe(0);
});

it('leaves the marker out when the site has disclosure off or the page used no AI', function (array $siteMeta, array $pageMeta): void {
    [$html] = renderAiDisclosureHead($siteMeta, $pageMeta);

    expect($html)->not->toContain('ai-disclosure');
})->with([
    'site off, AI page' => [['ai_disclosure' => 'off'], ['ai_disclosure' => 'generated']],
    'site unset, AI page' => [[], ['ai_disclosure' => 'assisted']],
    'site on, person-written page' => [['ai_disclosure' => 'marker'], ['ai_disclosure' => 'none']],
    'site on, unknown value' => [['ai_disclosure' => 'marker'], ['ai_disclosure' => '<script>']],
]);

/** The WebPage node of the head's JSON-LD graph. */
function aiDisclosureWebPageNode(string $html): array
{
    preg_match('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#s', $html, $match);
    $graph = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR)['@graph'];

    return collect($graph)->firstWhere('@type', 'WebPage');
}

it('declares the digital source type in the page JSON-LD when the site turns disclosure on', function (string $disclosure, string $sourceType): void {
    [$html, $provenanceQueries] = renderAiDisclosureHead(['ai_disclosure' => 'marker'], ['ai_disclosure' => $disclosure]);

    expect(aiDisclosureWebPageNode($html)['digitalSourceType'] ?? null)->toBe($sourceType)
        ->and($provenanceQueries)->toBe(0)
        ->and($html)->not->toContain('capell_ai_provenance');
})->with([
    'generated' => ['generated', 'https://schema.org/TrainedAlgorithmicMediaDigitalSource'],
    'assisted' => ['assisted', 'https://schema.org/CompositeWithTrainedAlgorithmicMediaDigitalSource'],
]);

it('leaves the digital source type out of the JSON-LD when disclosure is off or no AI wrote the page', function (array $siteMeta, array $pageMeta): void {
    [$html] = renderAiDisclosureHead($siteMeta, $pageMeta);

    expect(aiDisclosureWebPageNode($html))->not->toHaveKey('digitalSourceType')
        ->and($html)->not->toContain('digitalSourceType');
})->with([
    'site off, AI page' => [['ai_disclosure' => 'off'], ['ai_disclosure' => 'generated']],
    'site unset, AI page' => [[], ['ai_disclosure' => 'assisted']],
    'site on, person-written page' => [['ai_disclosure' => 'marker'], ['ai_disclosure' => 'none']],
    'site on, no provenance' => [['ai_disclosure' => 'marker'], []],
    'site on, unknown value' => [['ai_disclosure' => 'marker'], ['ai_disclosure' => '<script>']],
]);
