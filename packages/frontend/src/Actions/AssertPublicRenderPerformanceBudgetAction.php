<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\PublicRenderPerformanceBudgetData;
use Capell\Frontend\Data\PublicRenderPerformanceBudgetResultData;
use Capell\Frontend\Data\PublicRenderPerformanceReportData;
use Lorisleiva\Actions\Concerns\AsObject;

class AssertPublicRenderPerformanceBudgetAction
{
    use AsObject;

    public function handle(
        PublicRenderPerformanceReportData $report,
        ?PublicRenderPerformanceBudgetData $budget = null,
    ): PublicRenderPerformanceBudgetResultData {
        $budget ??= new PublicRenderPerformanceBudgetData;
        $failures = [];

        if (! $budget->allowJavaScript && ($report->assetCounts['js'] ?? 0) > 0) {
            $failures[] = 'Public render includes JavaScript assets.';
        }

        if (! $budget->allowJavaScript && ($report->runtimeModules['livewire'] ?? false)) {
            $failures[] = 'Public render enables Livewire runtime.';
        }

        if (! $budget->allowLivewire && ($report->runtimeModules['livewire'] ?? false)) {
            $failures[] = 'Public render enables Livewire runtime outside the route budget.';
        }

        if ($budget->expectCacheHit && $report->cacheHit !== true) {
            $failures[] = 'Public render did not satisfy the expected cache HIT budget.';
        }

        if (($report->byteCounts['inline'] ?? 0) > $budget->maxInlineBytes) {
            $failures[] = 'Inline script/style bytes exceed the static page budget.';
        }

        if (($report->byteCounts['js'] ?? 0) > $budget->maxJsBytes) {
            $failures[] = 'JavaScript bytes exceed the public render budget.';
        }

        if (($report->byteCounts['jsGzip'] ?? 0) > $budget->maxGzipJsBytes) {
            $failures[] = 'Gzipped JavaScript bytes exceed the public render budget.';
        }

        if (($report->byteCounts['css'] ?? 0) > $budget->maxCssBytes) {
            $failures[] = 'CSS bytes exceed the public render budget.';
        }

        if (($report->byteCounts['cssGzip'] ?? 0) > $budget->maxGzipCssBytes) {
            $failures[] = 'Gzipped CSS bytes exceed the public render budget.';
        }

        if (($report->byteCounts['criticalCss'] ?? 0) > $budget->maxCriticalCssBytes) {
            $failures[] = 'Critical CSS bytes exceed the public render budget.';
        }

        if (($report->assetCounts['css'] ?? 0) > $budget->maxCssAssets) {
            $failures[] = 'CSS asset count exceeds the public render budget.';
        }

        if (($report->assetCounts['mediaPreloads'] ?? 0) > $budget->maxMediaPreloads) {
            $failures[] = 'Media preload count exceeds the public render budget.';
        }

        return new PublicRenderPerformanceBudgetResultData(
            passes: $failures === [],
            failures: $failures,
        );
    }
}
