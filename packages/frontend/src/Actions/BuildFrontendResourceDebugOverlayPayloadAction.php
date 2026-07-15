<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildFrontendResourceDebugOverlayPayloadAction
{
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(Page $page): array
    {
        $diagnostics = BuildPageFrontendResourceDiagnosticsAction::run($page);
        $report = $diagnostics['report'];
        $budgetResult = $diagnostics['budgetResult'];
        $graph = is_array($diagnostics['graph'] ?? null) ? $diagnostics['graph'] : [];

        $conflicts = is_array($diagnostics['conflicts'] ?? null) ? $diagnostics['conflicts'] : [];
        $assets = is_array($graph['assets'] ?? null) ? $graph['assets'] : [];

        return [
            'summary' => [
                'cssAssets' => $report->assetCounts['css'] ?? 0,
                'jsAssets' => $report->assetCounts['js'] ?? 0,
                'cssRawBytes' => $report->byteCounts['cssRaw'] ?? 0,
                'cssGzipBytes' => $report->byteCounts['cssGzip'] ?? 0,
                'jsRawBytes' => $report->byteCounts['jsRaw'] ?? 0,
                'jsGzipBytes' => $report->byteCounts['jsGzip'] ?? 0,
                'budgetPasses' => $budgetResult->passes,
            ],
            'budgetFailures' => $budgetResult->failures,
            'conflicts' => collect($conflicts)
                ->filter(fn (mixed $conflict): bool => is_array($conflict))
                ->map(fn (array $conflict): array => [
                    'source' => $conflict['source'] ?? null,
                    'kind' => $conflict['kind'] ?? null,
                    'variants' => count($conflict['variants'] ?? []),
                ])
                ->values()
                ->all(),
            'assets' => collect($assets)
                ->filter(fn (mixed $asset): bool => is_array($asset))
                ->map(fn (array $asset): array => [
                    'source' => $asset['source'] ?? null,
                    'kind' => $asset['kind'] ?? null,
                    'placement' => $asset['placement'] ?? null,
                    'reasons' => $asset['reasons'] ?? [],
                ])
                ->values()
                ->all(),
        ];
    }
}
