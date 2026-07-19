<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Lorisleiva\Actions\Concerns\AsObject;

final class FinalizeMarketplaceInstallOperationTelemetryAction
{
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        ?int $runtimeMilliseconds = null,
        ?int $peakMemoryBytes = null,
        int $queryCount = 0,
    ): MarketplaceInstallAttempt {
        $attempt->refresh();
        $recordedAt = now();
        $stageTelemetry = $attempt->stage_telemetry ?? [];
        $currentStage = $attempt->current_stage;

        if (is_string($currentStage) && is_array($stageTelemetry[$currentStage] ?? null)) {
            $startedAt = data_get($stageTelemetry, $currentStage . '.started_at');
            $durationMilliseconds = is_string($startedAt)
                ? max(0, (int) round(now()->diffInMilliseconds($startedAt, true)))
                : null;

            $stageTelemetry[$currentStage] = [
                ...$stageTelemetry[$currentStage],
                'completed_at' => $recordedAt->toIso8601String(),
                'duration_ms' => $durationMilliseconds,
            ];
        }

        $failureContext = $attempt->failure_reason !== null
            ? array_filter([
                'type' => $attempt->failure_type,
                'stage' => $attempt->failure_stage,
                'reason' => $attempt->failure_reason,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
            : null;

        $attempt->forceFill([
            'heartbeat_at' => $recordedAt,
            'runtime_ms' => $runtimeMilliseconds ?? $this->runtimeFromTimestamps($attempt),
            'peak_memory_bytes' => $peakMemoryBytes,
            'query_count' => max($attempt->query_count, $queryCount),
            'stage_telemetry' => $stageTelemetry !== [] ? $stageTelemetry : null,
            'failure_context' => $failureContext,
        ])->save();

        return $attempt;
    }

    private function runtimeFromTimestamps(MarketplaceInstallAttempt $attempt): ?int
    {
        if ($attempt->started_at === null || $attempt->completed_at === null) {
            return null;
        }

        return max(0, (int) round($attempt->started_at->diffInMilliseconds($attempt->completed_at, true)));
    }
}
