<?php

declare(strict_types=1);

namespace Capell\Admin\Jobs;

use Capell\Core\Actions\Upgrade\ClaimQueuedUpgradeRunAction;
use Capell\Core\Actions\Upgrade\MarkUpgradeRunFinishedAction;
use Capell\Core\Actions\Upgrade\RecordUpgradeRunEventAction;
use Capell\Core\Actions\Upgrade\RequeueUpgradeRunAction;
use Capell\Core\Actions\Upgrade\RunCapellUpgradeAction;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Support\Upgrade\DatabaseUpgradeReporter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class RunCapellUpgradeJob implements ShouldQueue
{
    use Queueable;

    public const int LOCK_RETRY_DELAY_SECONDS = 60;

    public int $tries = 10;

    public int $timeout = 1200;

    public function __construct(
        private readonly int $upgradeRunId,
    ) {}

    public function upgradeRunId(): int
    {
        return $this->upgradeRunId;
    }

    public function handle(): void
    {
        $run = ClaimQueuedUpgradeRunAction::run($this->upgradeRunId);

        if (! $run instanceof UpgradeRun) {
            return;
        }

        $options = $this->options($run);

        if ($options->noClearCache) {
            RecordUpgradeRunEventAction::run(
                run: $run,
                level: UpgradeRunEventLevel::Info,
                message: 'Cache clearing skipped for queued upgrade; deploy cache clearing is expected to handle compiled caches.',
                stage: UpgradeStage::CacheClear,
            );
        }

        try {
            $exitCode = RunCapellUpgradeAction::run(
                $options,
                new DatabaseUpgradeReporter($run),
            );
        } catch (Throwable $throwable) {
            MarkUpgradeRunFinishedAction::run(
                run: $run->refresh(),
                status: UpgradeRunStatus::Failed,
                message: $throwable->getMessage(),
            );

            throw $throwable;
        }

        if ($exitCode === RunCapellUpgradeAction::UPGRADE_LOCKED) {
            RequeueUpgradeRunAction::run(
                run: $run->refresh(),
                message: sprintf(
                    'Upgrade coordination lock is busy; queued job will retry in %d seconds.',
                    self::LOCK_RETRY_DELAY_SECONDS,
                ),
            );

            $this->release(self::LOCK_RETRY_DELAY_SECONDS);

            return;
        }

        if ($exitCode !== Command::SUCCESS) {
            MarkUpgradeRunFinishedAction::run(
                run: $run->refresh(),
                status: UpgradeRunStatus::Failed,
                message: sprintf('Capell upgrade failed with exit code %d.', $exitCode),
            );

            throw new RuntimeException(sprintf('Capell upgrade failed with exit code %d.', $exitCode));
        }

        MarkUpgradeRunFinishedAction::run(
            run: $run->refresh(),
            status: UpgradeRunStatus::Succeeded,
            message: 'Capell upgrade completed successfully.',
        );
    }

    public function failed(?Throwable $throwable): void
    {
        $run = UpgradeRun::query()->find($this->upgradeRunId);

        if (! $run instanceof UpgradeRun || $run->status->isTerminal()) {
            return;
        }

        MarkUpgradeRunFinishedAction::run(
            run: $run,
            status: UpgradeRunStatus::Failed,
            message: $throwable?->getMessage() ?? 'Capell upgrade job failed before completion.',
        );
    }

    private function options(UpgradeRun $run): UpgradeRunOptions
    {
        $options = $run->options ?? [];

        return new UpgradeRunOptions(
            dryRun: (bool) ($options['dry_run'] ?? $run->dry_run),
            force: (bool) ($options['force'] ?? true),
            forceDowngrade: (bool) ($options['force_downgrade'] ?? false),
            noClearCache: (bool) ($options['no_clear_cache'] ?? true),
            skipMigrations: (bool) ($options['skip_migrations'] ?? false),
            skipSteps: (bool) ($options['skip_steps'] ?? false),
            onlyMigrations: (bool) ($options['only_migrations'] ?? false),
            onlySteps: (bool) ($options['only_steps'] ?? false),
            caches: $this->stringList($options['caches'] ?? []),
            forceStepIds: $this->stringList($options['force_step_ids'] ?? []),
            interactive: false,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
