<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Models\UpgradeRun;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class ClaimQueuedUpgradeRunAction
{
    use AsAction;

    public function handle(int $runId): ?UpgradeRun
    {
        return DB::transaction(function () use ($runId): ?UpgradeRun {
            $updated = UpgradeRun::query()
                ->whereKey($runId)
                ->where('status', UpgradeRunStatus::Queued)
                ->update([
                    'status' => UpgradeRunStatus::Running,
                    'current_stage' => UpgradeStage::Queue,
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            $run = UpgradeRun::query()->find($runId);

            if (! $run instanceof UpgradeRun) {
                return null;
            }

            RecordUpgradeRunEventAction::run(
                run: $run,
                level: UpgradeRunEventLevel::Info,
                message: 'Upgrade run started.',
                stage: UpgradeStage::Queue,
            );

            return $run;
        });
    }
}
