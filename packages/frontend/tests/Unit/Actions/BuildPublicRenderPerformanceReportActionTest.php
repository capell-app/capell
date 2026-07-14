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
    $plan = resolve(ResolveFrontendResourcePlanAction::class)->handle([
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
