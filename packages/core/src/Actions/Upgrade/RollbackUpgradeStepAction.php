<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Contracts\UpgradeStepContract;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Data\UpgradeStepResult;
use Capell\Core\Enums\Upgrade\UpgradeStepStatus;
use Capell\Core\Models\UpgradeLogEntry;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class RollbackUpgradeStepAction
{
    use AsFake;
    use AsObject;

    public function handle(UpgradeStepContract $step, UpgradeContext $context): UpgradeStepResult
    {
        $lastSuccess = UpgradeLogEntry::query()
            ->steps()
            ->where('key', $step->id())
            ->where('status', UpgradeStepStatus::Success->value)
            ->latest('ran_at')
            ->first();

        if ($lastSuccess === null) {
            return $this->result($step, UpgradeStepStatus::Skipped, 0, 'no successful run to roll back');
        }

        $started = microtime(true);

        try {
            $reversed = DB::transaction(fn (): bool => $step->rollback($context));
        } catch (Throwable $throwable) {
            return $this->recordFailure($step, $lastSuccess->id, $started, $throwable->getMessage());
        }

        if (! $reversed) {
            return $this->recordFailure($step, $lastSuccess->id, $started, 'rollback() returned false — step not reversible');
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        UpgradeLogEntry::query()->create([
            'type' => 'step',
            'key' => $step->id(),
            'package' => $step->package(),
            'status' => UpgradeStepStatus::RolledBack->value,
            'ran_at' => now(),
            'meta' => [
                'duration_ms' => $durationMs,
                'rollback_log_id' => $lastSuccess->id,
                'triggered_by' => 'rollback',
                'from_version' => $context->composerVersion($step->package()),
                'to_version' => $context->ledgerVersion($step->package()),
            ],
        ]);

        $lastSuccess->update(['status' => UpgradeStepStatus::Superseded->value]);

        return $this->result($step, UpgradeStepStatus::RolledBack, $durationMs);
    }

    private function recordFailure(
        UpgradeStepContract $step,
        int $targetId,
        float $startedAt,
        string $message,
    ): UpgradeStepResult {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        UpgradeLogEntry::query()->create([
            'type' => 'step',
            'key' => $step->id(),
            'package' => $step->package(),
            'status' => UpgradeStepStatus::Failed->value,
            'ran_at' => now(),
            'meta' => [
                'duration_ms' => $durationMs,
                'output' => $message,
                'rollback_log_id' => $targetId,
                'triggered_by' => 'rollback',
            ],
        ]);

        return $this->result($step, UpgradeStepStatus::Failed, $durationMs, $message);
    }

    private function result(UpgradeStepContract $step, UpgradeStepStatus $status, int $durationMs, ?string $output = null): UpgradeStepResult
    {
        return new UpgradeStepResult(
            stepId: $step->id(),
            label: $step->label(),
            status: $status->value,
            durationMs: $durationMs,
            output: $output,
        );
    }
}
