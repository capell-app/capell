<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Models\UpgradeRun;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class MarkUpgradeRunFinishedAction
{
    use AsAction;

    public function handle(
        UpgradeRun $run,
        UpgradeRunStatus $status,
        ?string $message = null,
        ?string $outputExcerpt = null,
    ): UpgradeRun {
        if ($run->status->isTerminal()) {
            return $run;
        }

        $stage = $status === UpgradeRunStatus::Succeeded ? UpgradeStage::Complete : UpgradeStage::Failed;
        $failureReason = $status === UpgradeRunStatus::Failed && $message !== null
            ? $this->redact($message)
            : null;

        $run->forceFill([
            'status' => $status,
            'current_stage' => $stage,
            'failure_reason' => $failureReason,
            'output_excerpt' => $this->excerpt($outputExcerpt),
            'finished_at' => now(),
        ])->save();

        RecordUpgradeRunEventAction::run(
            run: $run,
            level: $status === UpgradeRunStatus::Succeeded ? UpgradeRunEventLevel::Success : UpgradeRunEventLevel::Error,
            message: $message ?? ($status === UpgradeRunStatus::Succeeded ? 'Upgrade run succeeded.' : 'Upgrade run failed.'),
            stage: $stage,
            outputExcerpt: $outputExcerpt,
        );

        return $run->refresh();
    }

    private function redact(string $value): string
    {
        $redacted = RedactUpgradeRunContextAction::run(['value' => $value]);

        return is_string($redacted['value'] ?? null) ? $redacted['value'] : $value;
    }

    private function excerpt(?string $output): ?string
    {
        $output = trim((string) $output);

        if ($output === '') {
            return null;
        }

        $redacted = RedactUpgradeRunContextAction::run([
            'output' => Str::limit($output, 4000, ''),
        ]);

        return is_string($redacted['output'] ?? null) ? $redacted['output'] : null;
    }
}
