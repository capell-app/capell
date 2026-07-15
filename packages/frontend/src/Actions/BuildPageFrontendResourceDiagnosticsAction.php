<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicRenderPerformanceBudgetData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildPageFrontendResourceDiagnosticsAction
{
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(Page $page): array
    {
        $page->loadMissing([
            'layout.theme',
            'site.language',
            'site.theme',
        ]);

        $site = $page->site;
        $language = $site->language;
        $layout = $page->layout;
        $theme = $layout->theme instanceof Theme ? $layout->theme : $site->theme;

        $context = new FrontendRenderContextData(
            page: $page,
            site: $site,
            language: $language,
            layout: $layout,
            theme: $theme,
            runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
        );
        $renderData = BuildPublicPageRenderDataAction::run($context);
        $report = BuildPublicRenderPerformanceReportAction::run($renderData, $context);
        $budget = PublicRenderPerformanceBudgetData::forRouteType('content');
        $budgetResult = AssertPublicRenderPerformanceBudgetAction::run($report, $budget);

        return [
            'context' => [
                'page' => $page->name,
                'site' => $site->name,
                'language' => $language->name ?? $language->code,
                'layout' => $layout->name,
                'theme' => $theme->name ?? $theme->key,
            ],
            'graph' => BuildFrontendResourceGraphAction::run($renderData->resourcePlan, $context),
            'conflicts' => $renderData->resourcePlan->diagnostics,
            'report' => $report,
            'budget' => $budget,
            'budgetResult' => $budgetResult,
        ];
    }
}
