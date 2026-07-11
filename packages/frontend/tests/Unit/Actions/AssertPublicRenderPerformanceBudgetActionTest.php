<?php

declare(strict_types=1);

use Capell\Frontend\Actions\AssertPublicRenderPerformanceBudgetAction;
use Capell\Frontend\Data\PublicRenderPerformanceBudgetData;
use Capell\Frontend\Data\PublicRenderPerformanceReportData;

it('passes a static blade report with no javascript and a small css surface', function (): void {
    $result = AssertPublicRenderPerformanceBudgetAction::run(new PublicRenderPerformanceReportData(
        renderingStrategy: 'blade',
        runtimeModules: ['livewire' => false],
        assetCounts: ['css' => 1, 'js' => 0, 'inline' => 0, 'preloads' => 0, 'mediaPreloads' => 1],
        byteCounts: ['inline' => 0],
        surrogateKeys: [],
        assetReasons: [],
    ));

    expect($result->passes)->toBeTrue()
        ->and($result->failures)->toBeEmpty();
});

it('fails when a static public render includes javascript or too much inline code', function (): void {
    $result = AssertPublicRenderPerformanceBudgetAction::run(
        new PublicRenderPerformanceReportData(
            renderingStrategy: 'blade',
            runtimeModules: ['livewire' => true],
            assetCounts: ['css' => 3, 'js' => 1, 'inline' => 1, 'preloads' => 0, 'mediaPreloads' => 2],
            byteCounts: ['inline' => 1024],
            surrogateKeys: [],
            assetReasons: [],
        ),
        new PublicRenderPerformanceBudgetData(maxInlineBytes: 16, maxCssAssets: 1, maxMediaPreloads: 1),
    );

    expect($result->passes)->toBeFalse()
        ->and($result->failures)->toHaveCount(6);
});

it('provides route type budget defaults', function (): void {
    $budget = PublicRenderPerformanceBudgetData::forRouteType('contact');

    expect($budget->routeType)->toBe('contact')
        ->and($budget->allowLivewire)->toBeTrue()
        ->and($budget->maxJsBytes)->toBeGreaterThan(0);
});
