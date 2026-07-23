<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Upgrade\BuildUpgradePlanAction;
use Capell\Core\Actions\Upgrade\RollbackUpgradeStepAction;
use Capell\Core\Actions\Upgrade\RunCapellUpgradeAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Contracts\UpgradeStepContract;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\Upgrade\UpgradeStepStatus;
use Capell\Core\Support\Upgrade\DatabaseUpgradeLock;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class RollbackCommand extends Command
{
    use DescribesCommandOptions;

    protected $description = 'Roll back a previously-applied Capell upgrade step (if reversible).';

    protected $signature = 'capell:rollback
        {--step= : The step id to roll back}
        {--dry-run : Show what would happen without making changes}
        {--force : Skip confirmation}';

    public function handle(): int
    {
        $stepId = $this->option('step');
        $this->writeCommandIntro('roll back a Capell upgrade step', $this->rollbackIntroDetails($stepId));

        if (! is_string($stepId) || $stepId === '') {
            $this->error('Missing required --step=<id>.');

            return Command::FAILURE;
        }

        $step = $this->findStep($stepId);

        if (! $step instanceof UpgradeStepContract) {
            $this->error(sprintf('Unknown step id: %s', $stepId));

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('=== DRY RUN — no changes will be made ===');
        }

        if (! $this->option('force') && $this->input->isInteractive()
            && ! confirm(sprintf('Roll back "%s" [%s]?', $step->label(), $step->id()), default: false)) {
            $this->info('Aborted.');

            return Command::SUCCESS;
        }

        // Same durable lock as the upgrade itself, and the same TTL: a rollback that
        // assumed a shorter lifetime than the upgrade holding it could start while
        // that upgrade was still mid-migration.
        $lock = resolve(DatabaseUpgradeLock::class);
        $token = $lock->acquire(CacheEnum::UpgradeLock->value, RunCapellUpgradeAction::UPGRADE_LOCK_SECONDS, owner: gethostname() ?: null);

        if ($token === null) {
            $this->error('Another upgrade is running. Aborting.');

            return Command::FAILURE;
        }

        try {
            $plan = BuildUpgradePlanAction::run(dryRun: $dryRun, triggeredBy: 'rollback');

            if ($dryRun) {
                $this->line(sprintf('Would roll back: %s (%s)', $step->label(), $step->id()));

                return Command::SUCCESS;
            }

            $result = RollbackUpgradeStepAction::run($step, $plan->context);

            $status = UpgradeStepStatus::tryFrom($result->status);

            match ($status) {
                UpgradeStepStatus::RolledBack => $this->info(sprintf('Rolled back: %s', $step->id())),
                UpgradeStepStatus::Skipped => $this->warn(sprintf('Skipped: %s (%s)', $step->id(), $result->output ?? '')),
                default => $this->error(sprintf('Failed: %s (%s)', $step->id(), $result->output ?? '')),
            };

            return $status === UpgradeStepStatus::RolledBack ? Command::SUCCESS : Command::FAILURE;
        } finally {
            $lock->release(CacheEnum::UpgradeLock->value, $token);
        }
    }

    /**
     * @return array<int, string>
     */
    private function rollbackIntroDetails(mixed $stepId): array
    {
        return array_values(array_filter([
            is_string($stepId) && $stepId !== '' ? sprintf('step %s', $stepId) : null,
            $this->option('dry-run') ? 'a dry run' : null,
            $this->option('force') ? 'confirmation skipped' : null,
        ]));
    }

    private function findStep(string $id): ?UpgradeStepContract
    {
        foreach (app()->tagged('capell.upgrade-steps') as $candidate) {
            /** @var UpgradeStepContract $candidate */
            if ($candidate->id() === $id) {
                return $candidate;
            }
        }

        return null;
    }
}
