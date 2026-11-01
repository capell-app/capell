<?php

declare(strict_types=1);

use Capell\Frontend\Actions\BuildPublicRenderPerformanceReportAction;
use Capell\Frontend\Actions\ResolveFrontendResourcePlanAction;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;

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

function performanceReportRenderDataForLocalAssets(): PublicPageRenderData
{
    $directory = public_path('build-perf-report');
    @mkdir($directory, 0777, true);
    file_put_contents($directory . '/app.js', str_repeat('console.log("capell performance report");' . "\n", 20000));
    file_put_contents($directory . '/app.css', str_repeat('.capell-report{display:block;margin:0}' . "\n", 20000));

    $plan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData(FrontendResourceData::style('capell-app/theme:style', 'capell-app/theme', new PublicResourceSourceData('build-perf-report/app.css'))),
        new FrontendResourceContributionData(FrontendResourceData::moduleScript('capell-app/theme:script', 'capell-app/theme', new PublicResourceSourceData('build-perf-report/app.js'))),
    ]);

    return new PublicPageRenderData(null, null, null, null, null, null, FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly), $plan, []);
}

afterEach(function (): void {
    @unlink(public_path('build-perf-report/app.js'));
    @unlink(public_path('build-perf-report/app.css'));
    @rmdir(public_path('build-perf-report'));
});

it('leaves compressed sizes out of the public render report so a page view compresses nothing', function (): void {
    $renderData = performanceReportRenderDataForLocalAssets();

    $report = BuildPublicRenderPerformanceReportAction::run($renderData, measureCompressedBytes: false);

    expect($report->byteCounts)->not->toHaveKeys(['jsGzip', 'cssGzip'])
        ->and($report->byteCounts['jsRaw'])->toBe(filesize(public_path('build-perf-report/app.js')))
        ->and($report->byteCounts['cssRaw'])->toBe(filesize(public_path('build-perf-report/app.css')))
        ->and(cache()->get('capell:frontend:asset-gzip-bytes:' . hash('xxh128', realpath(public_path('build-perf-report/app.js')) . '|' . filesize(public_path('build-perf-report/app.js')) . '|' . filemtime(public_path('build-perf-report/app.js')))))->toBeNull();
});

it('measures compressed sizes for diagnostics once per asset version', function (): void {
    $renderData = performanceReportRenderDataForLocalAssets();
    $script = public_path('build-perf-report/app.js');
    $expected = strlen((string) gzencode((string) file_get_contents($script), 9));

    $first = BuildPublicRenderPerformanceReportAction::run($renderData);

    // A later request reuses the stored measurement while the file version is unchanged.
    app()->forgetScopedInstances();
    $modifiedAt = (int) filemtime($script);
    $size = (int) filesize($script);
    file_put_contents($script, str_repeat('x', $size));
    touch($script, $modifiedAt);
    $second = BuildPublicRenderPerformanceReportAction::run($renderData);

    // A rebuilt asset is a new version and is measured afresh.
    app()->forgetScopedInstances();
    touch($script, $modifiedAt + 10);
    $third = BuildPublicRenderPerformanceReportAction::run($renderData);

    expect($first->byteCounts['jsGzip'])->toBe($expected)
        ->and($first->byteCounts['cssGzip'])->toBeGreaterThan(0)
        ->and($second->byteCounts['jsGzip'])->toBe($expected)
        ->and($third->byteCounts['jsGzip'])->toBe(strlen((string) gzencode(str_repeat('x', $size), 9)));
});
