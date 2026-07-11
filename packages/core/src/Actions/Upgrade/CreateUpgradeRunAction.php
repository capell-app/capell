<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\Upgrade\UpgradeReadinessReportData;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Models\UpgradeRun;
use Lorisleiva\Actions\Concerns\AsAction;

final class CreateUpgradeRunAction
{
    use AsAction;

    /**
     * @param  list<string>  $manualCommands
     */
    public function handle(
        UpgradeRunOptions $options,
        UpgradeReadinessReportData $readiness,
        UpgradeRunStatus $status,
        array $manualCommands,
        ?int $userId = null,
    ): UpgradeRun {
        $now = now();

        $run = UpgradeRun::query()->create([
            'status' => $status,
            'dry_run' => $options->dryRun,
            'user_id' => $userId,
            'options' => $this->optionsPayload($options),
            'manual_commands' => $manualCommands,
            'readiness_warnings' => $this->redactList($readiness->warnings),
            'readiness_errors' => $this->redactList($readiness->errors),
            'current_stage' => $status === UpgradeRunStatus::ManualRequired ? UpgradeStage::Readiness : UpgradeStage::Queue,
            'queued_at' => $status === UpgradeRunStatus::Queued ? $now : null,
            'started_at' => $status === UpgradeRunStatus::Running ? $now : null,
            'finished_at' => $status === UpgradeRunStatus::ManualRequired ? $now : null,
        ]);

        RecordUpgradeRunEventAction::run(
            run: $run,
            level: $this->eventLevel($status),
            message: $this->eventMessage($status),
            stage: $status === UpgradeRunStatus::ManualRequired ? UpgradeStage::Readiness : UpgradeStage::Queue,
            context: [
                'warnings' => $readiness->warnings,
                'errors' => $readiness->errors,
                'manual_commands' => $manualCommands,
            ],
        );

        return $run;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function redactList(array $values): array
    {
        $redacted = RedactUpgradeRunContextAction::run(['values' => $values]);

        return is_array($redacted['values'] ?? null)
            ? array_values(array_filter($redacted['values'], is_string(...)))
            : $values;
    }

    /** @return array<string, mixed> */
    private function optionsPayload(UpgradeRunOptions $options): array
    {
        return [
            'dry_run' => $options->dryRun,
            'force' => $options->force,
            'force_downgrade' => $options->forceDowngrade,
            'no_clear_cache' => $options->noClearCache,
            'skip_migrations' => $options->skipMigrations,
            'skip_steps' => $options->skipSteps,
            'only_migrations' => $options->onlyMigrations,
            'only_steps' => $options->onlySteps,
            'caches' => $options->caches,
            'force_step_ids' => $options->forceStepIds,
            'interactive' => $options->interactive,
        ];
    }

    private function eventLevel(UpgradeRunStatus $status): UpgradeRunEventLevel
    {
        return match ($status) {
            UpgradeRunStatus::ManualRequired => UpgradeRunEventLevel::Warning,
            default => UpgradeRunEventLevel::Info,
        };
    }

    private function eventMessage(UpgradeRunStatus $status): string
    {
        return match ($status) {
            UpgradeRunStatus::Queued => 'Upgrade run queued.',
            UpgradeRunStatus::Running => 'Upgrade run started.',
            UpgradeRunStatus::ManualRequired => 'Manual upgrade required.',
            default => 'Upgrade run recorded.',
        };
    }
}
