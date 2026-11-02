<?php

declare(strict_types=1);

use Capell\Frontend\Actions\BuildPublicRenderPerformanceReportAction;
use Capell\Frontend\Actions\ResolveFrontendResourcePlanAction;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\FrontendResourcePlacement;
use Capell\Frontend\Enums\FrontendResourceSourceKind;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Assets\CompressedAssetSizeResolver;
use Illuminate\Support\Facades\Cache;

it('summarises the resolved resource plan without fetching remote resources', function (): void {
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $runtime->usesInertia = true;

    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData(FrontendResourceData::style('capell-app/theme:style', 'capell-app/theme', new PublicResourceSourceData('theme.css'))),
        new FrontendResourceContributionData(FrontendResourceData::inlineStyle('capell-app/theme:critical', 'capell-app/theme', '.hero{display:block}', criticalCssEligible: true)),
    ]);
    $renderData = new PublicPageRenderData(null, null, null, null, null, null, $runtime, $plan, ['page-1']);

    $report = BuildPublicRenderPerformanceReportAction::run($renderData);

    expect($report->renderingStrategy)->toBe('blade')
        ->and($report->runtimeModules['livewire'])->toBeFalse()
        ->and($report->runtimeModules['inertia'])->toBeTrue()
        ->and($report->assetCounts['css'])->toBe(2)
        ->and($report->assetCounts['js'])->toBe(0)
        ->and($report->byteCounts['inline'])->toBe(strlen('.hero{display:block}'))
        ->and($report->byteCounts['criticalCss'])->toBe(strlen('.hero{display:block}'))
        ->and($report->assetReasons[0]['handle'])->toBe('capell-app/theme:style')
        ->and($report->surrogateKeys)->toBe(['page-1']);
});

function gzipCountingResolver(): object
{
    $resolver = new class extends CompressedAssetSizeResolver
    {
        public int $compressions = 0;

        #[Override]
        protected function compress(string $path): int
        {
            $this->compressions++;

            return parent::compress($path);
        }
    };
    app()->instance(CompressedAssetSizeResolver::class, $resolver);

    return $resolver;
}

function renderDataWithLocalScript(string $path): PublicPageRenderData
{
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $resource = new ResolvedFrontendResourceData(
        'tok',
        'theme:script',
        'capell-app/theme',
        FrontendResourceKind::ModuleScript,
        '/app.js',
        null,
        FrontendResourcePlacement::BodyEnd,
        [],
        false,
        null,
        false,
        false,
        sourceKind: FrontendResourceSourceKind::PublicPath,
        localPath: $path,
    );
    $plan = new FrontendResourcePlanData([], [$resource], [], [], [], [], [], 'fp');

    return new PublicPageRenderData(null, null, null, null, null, null, $runtime, $plan, []);
}

it('does not gzip local assets on the default public render path', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-js');
    file_put_contents($path, str_repeat('console.log(1);', 200));
    $resolver = gzipCountingResolver();

    $report = BuildPublicRenderPerformanceReportAction::run(renderDataWithLocalScript($path));

    expect($resolver->compressions)->toBe(0)
        ->and($report->byteCounts['jsRaw'])->toBe(filesize($path))
        ->and($report->byteCounts['jsGzip'])->toBe(0);
    unlink($path);
});

it('gzips once per asset hash when the overlay or budget asks for compressed sizes', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-js');
    file_put_contents($path, str_repeat('console.log(2);', 200));
    Cache::flush();
    $resolver = gzipCountingResolver();

    $first = BuildPublicRenderPerformanceReportAction::run(renderDataWithLocalScript($path), null, null, true);
    $second = BuildPublicRenderPerformanceReportAction::run(renderDataWithLocalScript($path), null, null, true);

    expect($first->byteCounts['jsGzip'])->toBeGreaterThan(0)
        ->and($second->byteCounts['jsGzip'])->toBe($first->byteCounts['jsGzip'])
        ->and($resolver->compressions)->toBe(1);
    unlink($path);
});
