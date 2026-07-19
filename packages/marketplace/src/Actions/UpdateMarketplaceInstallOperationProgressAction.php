<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class UpdateMarketplaceInstallOperationProgressAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallFailureStage $stage,
        int $progressCurrent,
        int $progressTotal,
        ?int $attemptCount = null,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $stage, $progressCurrent, $progressTotal, $attemptCount): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $recordedAt = now();
            $stageTelemetry = $lockedAttempt->stage_telemetry ?? [];
            $stageKey = $stage->value;
            $stageState = is_array($stageTelemetry[$stageKey] ?? null)
                ? $stageTelemetry[$stageKey]
                : [];

            $stageTelemetry[$stageKey] = [
                ...$stageState,
                'started_at' => $stageState['started_at'] ?? $recordedAt->toIso8601String(),
                'heartbeat_at' => $recordedAt->toIso8601String(),
            ];

            $attributes = [
                'current_stage' => $stageKey,
                'progress_current' => max(0, min($progressCurrent, $progressTotal)),
                'progress_total' => max(1, $progressTotal),
                'heartbeat_at' => $recordedAt,
                'stage_telemetry' => $stageTelemetry,
            ];

            if ($attemptCount !== null) {
                $attributes['attempt_count'] = max($lockedAttempt->attempt_count, $attemptCount);
            }

            $lockedAttempt->forceFill($attributes)->save();

            return $lockedAttempt;
        });
    }
}
