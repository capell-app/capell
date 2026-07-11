<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Actions\DetectFrontendResourceConflictsAction;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;

it('detects same asset sources with conflicting loading options', function (): void {
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $manifest = new FrontendAssetManifestData(
        css: [
            new FrontendAssetRequirementData('theme', FrontendAssetRequirementData::KIND_CSS, 'resources/css/widget.css', 'build'),
            new FrontendAssetRequirementData('widget', FrontendAssetRequirementData::KIND_CSS, 'resources/css/widget.css', 'build', loadingStrategy: PresentationLoadingStrategy::Visible),
        ],
        js: [],
        inline: [],
        preloads: [],
        runtime: $runtime,
    );

    $conflicts = DetectFrontendResourceConflictsAction::run($manifest);

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['source'])->toBe('resources/css/widget.css')
        ->and($conflicts[0]['variants'])->toHaveCount(2);
});

it('detects conflicts from raw requirements that were deduplicated for public delivery', function (): void {
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $first = new FrontendAssetRequirementData('theme', FrontendAssetRequirementData::KIND_JS, 'resources/js/widget.js', 'build');
    $second = new FrontendAssetRequirementData(
        handle: 'widget',
        kind: FrontendAssetRequirementData::KIND_JS,
        source: 'resources/js/widget.js',
        buildPath: 'build',
        defer: true,
    );
    $manifest = new FrontendAssetManifestData(
        css: [],
        js: [$first],
        inline: [],
        preloads: [],
        runtime: $runtime,
        rawRequirements: [$first, $second],
    );

    $conflicts = DetectFrontendResourceConflictsAction::run($manifest);

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['source'])->toBe('resources/js/widget.js')
        ->and($conflicts[0]['variants'])->toHaveCount(2);
});
