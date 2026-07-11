<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Models\UpgradeRun;
use Lorisleiva\Actions\Concerns\AsAction;

final class RequeueUpgradeRunAction
{
    use AsAction;

    public function handle(UpgradeRun $run, string $message): UpgradeRun
    {
        if ($run->status->isTerminal()) {
            return $run;
        }

        $run->forceFill([
            'status' => UpgradeRunStatus::Queued,
            'current_stage' => UpgradeStage::Queue,
            'started_at' => null,
            'updated_at' => now(),
        ])->save();

        RecordUpgradeRunEventAction::run(
            run: $run,
            level: UpgradeRunEventLevel::Warning,
            message: $message,
            stage: UpgradeStage::Queue,
        );

        return $run->refresh();
    }
}
