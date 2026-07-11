<?php

declare(strict_types=1);

use Capell\Frontend\Actions\MeasureFrontendAssetSizesAction;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Illuminate\Support\Facades\File;

it('measures raw and gzip totals for local build css and javascript assets', function (): void {
    $cssPath = public_path('build/resources/css/diagnostics.css');
    $jsPath = public_path('build/resources/js/diagnostics.js');

    File::ensureDirectoryExists(dirname($cssPath));
    File::ensureDirectoryExists(dirname($jsPath));
    File::put($cssPath, '.hero{display:block}');
    File::put($jsPath, 'console.log("diagnostics");');

    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $report = MeasureFrontendAssetSizesAction::run(new FrontendAssetManifestData(
        css: [new FrontendAssetRequirementData('diagnostics-css', FrontendAssetRequirementData::KIND_CSS, 'resources/css/diagnostics.css', 'build')],
        js: [new FrontendAssetRequirementData('diagnostics-js', FrontendAssetRequirementData::KIND_JS, 'resources/js/diagnostics.js', 'build')],
        inline: [],
        preloads: [],
        runtime: $runtime,
    ));

    expect($report->rawCssBytes)->toBe(strlen('.hero{display:block}'))
        ->and($report->gzipCssBytes)->toBeGreaterThan(0)
        ->and($report->rawJsBytes)->toBe(strlen('console.log("diagnostics");'))
        ->and($report->gzipJsBytes)->toBeGreaterThan(0)
        ->and($report->warnings)->toBe([]);
});

it('warns when external or missing assets cannot be measured', function (): void {
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);

    $report = MeasureFrontendAssetSizesAction::run(new FrontendAssetManifestData(
        css: [new FrontendAssetRequirementData('external', FrontendAssetRequirementData::KIND_CSS, 'https://cdn.example.com/app.css')],
        js: [new FrontendAssetRequirementData('missing', FrontendAssetRequirementData::KIND_JS, 'resources/js/missing.js', 'build')],
        inline: [],
        preloads: [],
        runtime: $runtime,
    ));

    expect($report->rawCssBytes)->toBe(0)
        ->and($report->rawJsBytes)->toBe(0)
        ->and($report->warnings)->toHaveCount(2);
});
