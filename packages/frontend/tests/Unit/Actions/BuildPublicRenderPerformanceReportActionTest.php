<?php

declare(strict_types=1);

use Capell\Frontend\Actions\BuildPublicRenderPerformanceReportAction;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;

it('summarises runtime modules asset counts byte counts and asset inclusion reasons', function (): void {
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $runtime->usesInertia = true;

    $renderData = new PublicPageRenderData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
        layoutGraph: null,
        runtimeManifest: $runtime,
        assetManifest: new FrontendAssetManifestData(
            css: [
                new FrontendAssetRequirementData('theme', FrontendAssetRequirementData::KIND_CSS, 'resources/css/theme.css', 'build'),
            ],
            js: [],
            inline: [
                new FrontendAssetRequirementData('critical-inline', FrontendAssetRequirementData::KIND_INLINE, '<style>.hero{display:block}</style>'),
            ],
            preloads: [],
            runtime: $runtime,
        ),
        surrogateKeys: ['page-1'],
    );

    $report = BuildPublicRenderPerformanceReportAction::run($renderData);

    expect($report->renderingStrategy)->toBe('blade')
        ->and($report->runtimeModules['livewire'])->toBeFalse()
        ->and($report->runtimeModules['inertia'])->toBeTrue()
        ->and($report->assetCounts['css'])->toBe(1)
        ->and($report->assetCounts['js'])->toBe(0)
        ->and($report->byteCounts['inline'])->toBe(strlen('<style>.hero{display:block}</style>'))
        ->and($report->byteCounts['criticalCss'])->toBe(strlen('<style>.hero{display:block}</style>'))
        ->and($report->byteCounts['css'])->toBe(strlen('resources/css/theme.css'))
        ->and($report->assetReasons[0]['handle'])->toBe('theme')
        ->and($report->surrogateKeys)->toBe(['page-1']);
});
