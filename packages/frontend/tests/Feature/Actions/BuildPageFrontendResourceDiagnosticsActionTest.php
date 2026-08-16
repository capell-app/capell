<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Frontend\Actions\BuildFrontendResourceDebugOverlayPayloadAction;
use Capell\Frontend\Actions\BuildPageFrontendResourceDiagnosticsAction;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Data\PublicRenderPerformanceBudgetResultData;
use Capell\Frontend\Data\PublicRenderPerformanceReportData;

it('builds resource-plan ownership graph diagnostics and budget data for a page', function (): void {
    app()->bind('test.frontend-resource-diagnostics-contributor', fn (): FrontendResourceContributor => new class implements FrontendResourceContributor
    {
        public function resources(FrontendResourceContextData $context): array
        {
            return [new FrontendResourceContributionData(FrontendResourceData::style(
                'capell-app/gallery:styles',
                'capell-app/gallery',
                new PublicResourceSourceData('vendor/gallery/gallery.css'),
            ))];
        }
    });
    app()->tag('test.frontend-resource-diagnostics-contributor', FrontendResourceContributor::TAG);

    $diagnostics = BuildPageFrontendResourceDiagnosticsAction::run(Page::factory()->createOne());

    expect($diagnostics['context']['page'])->not->toBeNull()
        ->and($diagnostics['graph']['assets'])->toHaveCount(1)
        ->and($diagnostics['graph']['assets'][0]['source'])->toEndWith('/vendor/gallery/gallery.css')
        ->and($diagnostics['graph']['assets'][0]['package'])->toBe('capell-app/gallery')
        ->and($diagnostics['conflicts'])->toBe([])
        ->and($diagnostics['budgetResult']->passes)->toBeBool();
});

it('builds a filtered debug overlay payload from resource diagnostics', function (): void {
    $page = Page::factory()->createOne();

    BuildPageFrontendResourceDiagnosticsAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->with($page)
        ->andReturn([
            'report' => new PublicRenderPerformanceReportData(
                renderingStrategy: 'blade',
                runtimeModules: [],
                assetCounts: ['css' => 2, 'js' => 1],
                byteCounts: ['cssRaw' => 1200, 'cssGzip' => 300, 'jsRaw' => 900, 'jsGzip' => 240],
                surrogateKeys: [],
                assetReasons: [],
            ),
            'budgetResult' => new PublicRenderPerformanceBudgetResultData(
                passes: false,
                failures: ['js budget exceeded'],
            ),
            'conflicts' => [
                ['source' => 'vendor/a.css', 'kind' => 'style', 'variants' => ['a', 'b']],
                'ignore non-array conflicts',
            ],
            'graph' => [
                'assets' => [
                    [
                        'source' => 'vendor/a.css',
                        'kind' => 'style',
                        'placement' => 'head',
                        'reasons' => ['theme'],
                    ],
                    'ignore non-array assets',
                ],
            ],
        ]);

    try {
        expect(BuildFrontendResourceDebugOverlayPayloadAction::run($page))->toBe([
            'summary' => [
                'cssAssets' => 2,
                'jsAssets' => 1,
                'cssRawBytes' => 1200,
                'cssGzipBytes' => 300,
                'jsRawBytes' => 900,
                'jsGzipBytes' => 240,
                'budgetPasses' => false,
            ],
            'budgetFailures' => ['js budget exceeded'],
            'conflicts' => [[
                'source' => 'vendor/a.css',
                'kind' => 'style',
                'variants' => 2,
            ]],
            'assets' => [[
                'source' => 'vendor/a.css',
                'kind' => 'style',
                'placement' => 'head',
                'reasons' => ['theme'],
            ]],
        ]);
    } finally {
        BuildPageFrontendResourceDiagnosticsAction::clearFake();
    }
});
