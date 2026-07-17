<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Contracts\UpgradeReporter;
use Capell\Core\Contracts\UpgradeStepContract;
use Capell\Core\Data\PackageData;
use Capell\Core\Data\UpgradePlanData;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Data\VersionAudit;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Enums\Upgrade\UpgradeStepStatus;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Upgrade\NullUpgradeReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class RunCapellUpgradeAction
{
    use AsFake;
    use AsObject;

    public const int UPGRADE_LOCK_SECONDS = 1500;

    public const int UPGRADE_LOCKED = 75;

    public function handle(UpgradeRunOptions $options, ?UpgradeReporter $reporter = null): int
    {
        $reporter ??= new NullUpgradeReporter;

        $lock = Cache::lock('capell:upgrade', self::UPGRADE_LOCK_SECONDS);

        if ($lock->get() === false) {
            $reporter->error('Another upgrade is running (cache lock "capell:upgrade" held). Aborting. If no upgrade is active, retry after the lock TTL expires, up to 25 minutes after a hard process kill.');

            return self::UPGRADE_LOCKED;
        }

        try {
            return $this->runPipeline($options, $reporter);
        } finally {
            $lock->release();
        }
    }

    private function runPipeline(UpgradeRunOptions $options, UpgradeReporter $reporter): int
    {
        $this->printHeader($options, $reporter);

        $plan = BuildUpgradePlanAction::run(dryRun: $options->dryRun);

        if (! $this->validateAudit($plan->versionAudit, $options, $reporter)) {
            return Command::FAILURE;
        }

        $doMigrations = ! $options->skipMigrations && ! $options->onlySteps;
        $doSteps = ! $options->skipSteps && ! $options->onlyMigrations;

        if (! $this->validateForcedStepIds($plan, $options->forceStepIds, $reporter)) {
            return Command::FAILURE;
        }

        if ($doMigrations && ! $this->runMigrationPhase($options->dryRun, $reporter)) {
            $reporter->error('Migration phase failed.');

            return Command::FAILURE;
        }

        $stepsDeclined = false;

        if ($doSteps && ! $this->runUpgradeStepsPhase($plan, $options, $reporter, $stepsDeclined)) {
            $reporter->error('One or more upgrade steps failed.');

            return Command::FAILURE;
        }

        if (! $options->dryRun && ! $options->onlyMigrations && ! $options->onlySteps
            && ! $this->runPerPackageUpgradeCommandsPhase($reporter)) {
            $reporter->error('One or more per-package upgrade commands failed.');

            return Command::FAILURE;
        }

        $versionLedgerSkipReason = $this->versionLedgerSkipReason($options, $stepsDeclined);

        if ($versionLedgerSkipReason === null) {
            $this->recordVersions($plan, $options->dryRun, $reporter);
        } else {
            $this->skipVersionLedger($versionLedgerSkipReason, $reporter);
        }

        if (! $options->dryRun && ! $options->noClearCache && ! $this->cacheClearMenu($options, $reporter)) {
            $reporter->error('One or more cache clear commands failed.');

            return Command::FAILURE;
        }

        $reporter->newLine();
        $reporter->stage(UpgradeStage::Complete, 'Upgrade pipeline completed.');
        $reporter->info($options->dryRun ? 'Dry run complete — no changes were made.' : 'Upgrade complete!');

        return Command::SUCCESS;
    }

    private function printHeader(UpgradeRunOptions $options, UpgradeReporter $reporter): void
    {
        if ($options->dryRun) {
            $reporter->warn('=== DRY RUN — no changes will be made ===');
        }

        $reporter->info('Capell upgrade starting...');
        $reporter->newLine();
    }

    private function validateAudit(VersionAudit $audit, UpgradeRunOptions $options, UpgradeReporter $reporter): bool
    {
        if (! $audit->hasIssues()) {
            return true;
        }

        $reporter->line('<fg=blue;options=bold>Version audit</>');
        foreach ($audit->composerOnly as $package) {
            $reporter->line(sprintf('  <fg=yellow>+</> %s — new in Composer, no ledger row yet', $package));
        }

        foreach ($audit->ledgerOnly as $package) {
            $reporter->line(sprintf('  <fg=yellow>−</> %s — in ledger but no longer in Composer', $package));
        }

        foreach ($audit->downgrades as $package => $range) {
            $reporter->line(sprintf('  <fg=red>↓</> %s — downgrade (%s → %s)', $package, $range['from'], $range['to']));
        }

        $reporter->newLine();

        if ($audit->downgrades !== [] && ! $options->forceDowngrade && ! $options->dryRun) {
            $reporter->error('Downgrade detected. Re-run with --force-downgrade to proceed.');

            return false;
        }

        return true;
    }

    private function runMigrationPhase(bool $dryRun, UpgradeReporter $reporter): bool
    {
        $reporter->stage(UpgradeStage::Migrations, 'Migration phase started.');

        $lockFile = $this->migrationLockFile();

        try {
            throw_unless(flock($lockFile, LOCK_EX), RuntimeException::class, 'Could not lock Capell database migrations.');

            return $this->runLockedMigrationPhase($dryRun, $reporter);
        } finally {
            flock($lockFile, LOCK_UN);
            fclose($lockFile);
        }
    }

    private function runLockedMigrationPhase(bool $dryRun, UpgradeReporter $reporter): bool
    {
        $reporter->line('<fg=blue;options=bold>Phase 1: Migrations</>');
        $published = PublishPendingMigrationsAction::run(dryRun: $dryRun);
        $reporter->line(sprintf(
            '  Published schema=%s settings=%s',
            $published->schemaPublished ? 'yes' : 'no',
            $published->settingsPublished ? 'yes' : 'no',
        ));

        $schema = RunDatabaseMigrationsAction::run(dryRun: $dryRun);
        $reporter->line(sprintf('  migrate exit=%d', $schema->exitCode));

        $settings = RunSettingsMigrationsAction::run(dryRun: $dryRun);
        $reporter->line(sprintf('  settings:migrate exit=%d', $settings->exitCode));
        $reporter->newLine();

        if ($dryRun) {
            return true;
        }

        return $published->schemaPublished
            && $published->settingsPublished
            && $schema->exitCode === Command::SUCCESS
            && $settings->exitCode === Command::SUCCESS;
    }

    /**
     * @return resource
     */
    private function migrationLockFile()
    {
        $lockPath = storage_path('framework/cache/capell-database-migrations.lock');
        File::ensureDirectoryExists(dirname($lockPath));

        $lockFile = fopen($lockPath, 'c');

        throw_unless(is_resource($lockFile), RuntimeException::class, 'Could not open Capell database migration lock.');

        return $lockFile;
    }

    private function runUpgradeStepsPhase(
        UpgradePlanData $plan,
        UpgradeRunOptions $options,
        UpgradeReporter $reporter,
        bool &$stepsDeclined,
    ): bool {
        $stepsDeclined = false;

        $reporter->stage(UpgradeStage::UpgradeSteps, 'Upgrade steps phase started.');
        $reporter->line('<fg=blue;options=bold>Phase 2: Upgrade steps</>');

        $pending = $this->stepsForRun($plan, $options->forceStepIds);

        if ($pending === [] && $options->forceStepIds === []) {
            $reporter->line('  No pending upgrade steps.');
            $reporter->newLine();

            return true;
        }

        foreach ($pending as $step) {
            $reporter->line(sprintf('  <fg=yellow>→</> [%s] %s (priority %d)', $step->id(), $step->label(), $step->priority()));
        }

        if (! $options->dryRun && ! $options->force && $options->interactive
            && ! $reporter->confirm('Run the above upgrade steps?', default: true)) {
            $reporter->info('  Skipped by user.');
            $reporter->newLine();
            $stepsDeclined = true;

            return true;
        }

        $allPassed = true;

        foreach ($pending as $step) {
            $force = in_array($step->id(), $options->forceStepIds, true);
            $reporter->line(sprintf('  Running: %s', $step->label()));
            $result = RunUpgradeStepAction::run($step, $plan->context, force: $force);

            $status = UpgradeStepStatus::tryFrom($result->status);

            match ($status) {
                UpgradeStepStatus::Success => $reporter->line('    <fg=green>✓ Done</>'),
                UpgradeStepStatus::Skipped => $reporter->line(sprintf('    <fg=yellow>— Skipped</> (%s)', $result->output ?? '')),
                UpgradeStepStatus::DryRun => $reporter->line('    <fg=cyan>… Dry run (would execute)</>'),
                UpgradeStepStatus::Failed => $reporter->line(sprintf('    <fg=red>✗ Failed</> %s', $result->output ?? '')),
                default => $reporter->line(sprintf('    %s', $result->status)),
            };

            if ($status?->completedUpgradeRun() !== true) {
                $allPassed = false;
            }
        }

        $reporter->newLine();

        return $allPassed;
    }

    /**
     * @param  list<string>  $forcedIds
     * @return UpgradeStepContract[]
     */
    private function stepsForRun(UpgradePlanData $plan, array $forcedIds): array
    {
        if ($forcedIds === []) {
            return $plan->pendingSteps;
        }

        /** @var array<string, UpgradeStepContract> $steps */
        $steps = [];
        foreach ($plan->pendingSteps as $step) {
            $steps[$step->id()] = $step;
        }

        foreach (app()->tagged('capell.upgrade-steps') as $candidate) {
            if (! $candidate instanceof UpgradeStepContract) {
                continue;
            }

            if (! in_array($candidate->id(), $forcedIds, true)) {
                continue;
            }

            $steps[$candidate->id()] = $candidate;
        }

        return SortUpgradeStepsAction::run(array_values($steps));
    }

    /**
     * @param  list<string>  $forcedIds
     */
    private function validateForcedStepIds(UpgradePlanData $plan, array $forcedIds, UpgradeReporter $reporter): bool
    {
        if ($forcedIds === []) {
            return true;
        }

        /** @var array<string, true> $knownStepIds */
        $knownStepIds = [];

        foreach ($plan->pendingSteps as $step) {
            $knownStepIds[$step->id()] = true;
        }

        foreach (app()->tagged('capell.upgrade-steps') as $candidate) {
            if (! $candidate instanceof UpgradeStepContract) {
                continue;
            }

            $knownStepIds[$candidate->id()] = true;
        }

        foreach ($forcedIds as $forcedId) {
            if (array_key_exists($forcedId, $knownStepIds)) {
                continue;
            }

            $reporter->error(sprintf('Unknown forced step id: %s', $forcedId));

            return false;
        }

        return true;
    }

    private function runPerPackageUpgradeCommandsPhase(UpgradeReporter $reporter): bool
    {
        $reporter->stage(UpgradeStage::LegacyPackageCommands, 'Legacy per-package command phase started.');
        $reporter->line('<fg=blue;options=bold>Phase 3: Per-package commands</>');

        $passed = true;

        CapellCore::getPackages()->each(function (PackageData $package) use (&$passed, $reporter): void {
            $command = $package->getUpgradeCommand();

            if (in_array($command, [null, '', '0'], true)) {
                return;
            }

            $reporter->warn(sprintf(
                'Package %s uses legacy manifest upgrade command "%s"; prefer a tagged UpgradeStepContract.',
                $package->name,
                $command,
            ));

            $commandName = str($command)->before(' ')->trim()->toString();

            if (! $reporter->commandExists($commandName)) {
                $reporter->warn(sprintf(
                    'Package %s legacy upgrade command "%s" is not registered; skipping.',
                    $package->name,
                    $commandName,
                ));

                return;
            }

            $reporter->line(sprintf('  Running %s (%s)', $package->name, $command));

            $exitCode = $reporter->callCommand($command);

            if ($exitCode !== Command::SUCCESS) {
                $reporter->error(sprintf('  %s failed with exit code %d', $command, $exitCode));
                $passed = false;
            }
        });

        $reporter->newLine();

        return $passed;
    }

    private function recordVersions(UpgradePlanData $plan, bool $dryRun, UpgradeReporter $reporter): void
    {
        $reporter->stage(UpgradeStage::VersionLedger, 'Version ledger phase started.');
        $reporter->line('<fg=blue;options=bold>Phase 4: Record versions</>');

        $composerVersions = $plan->context->composerVersions;

        if ($composerVersions === []) {
            $reporter->line('  No packages to record.');
            $reporter->newLine();

            return;
        }

        RecordVersionSnapshotAction::run($composerVersions, dryRun: $dryRun);

        foreach ($composerVersions as $package => $version) {
            $reporter->line(sprintf('  %s => %s%s', $package, $version, $dryRun ? ' [dry-run]' : ''));
        }

        $reporter->newLine();
    }

    private function versionLedgerSkipReason(UpgradeRunOptions $options, bool $stepsDeclined): ?string
    {
        if ($options->dryRun) {
            return null;
        }

        if ($stepsDeclined) {
            return 'Upgrade steps were declined; version ledger was not advanced.';
        }

        if ($options->skipMigrations || $options->skipSteps || $options->onlyMigrations || $options->onlySteps) {
            return 'Partial upgrade run; version ledger was not advanced.';
        }

        return null;
    }

    private function skipVersionLedger(string $reason, UpgradeReporter $reporter): void
    {
        $reporter->stage(UpgradeStage::VersionLedger, 'Version ledger phase skipped.');
        $reporter->line('<fg=blue;options=bold>Phase 4: Record versions</>');
        $reporter->line(sprintf('  %s', $reason));
        $reporter->newLine();
    }

    private function cacheClearMenu(UpgradeRunOptions $options, UpgradeReporter $reporter): bool
    {
        $reporter->stage(UpgradeStage::CacheClear, 'Cache clear phase started.');

        if ($options->caches !== []) {
            $selected = $options->caches;
        } elseif ($options->force) {
            $selected = ['all'];
        } else {
            $reporter->line('No cache selection provided; skipping cache clearing. Use --force to clear all caches or --caches=page,config,views to choose specific caches.');

            return true;
        }

        $passed = true;

        if (in_array('all', $selected, true)) {
            if ($this->shouldSkipOptimizeClearForTestbench()) {
                $reporter->line('Skipped optimize:clear; Testbench package manifests are shared across parallel tests');
            } else {
                $passed = $this->clearCache($reporter, 'optimize:clear');
            }

            return $passed;
        }

        if (in_array('page', $selected, true)) {
            if ($reporter->commandExists('capell:html-cache:clear')) {
                $passed = $this->clearCache($reporter, 'capell:html-cache:clear');
            } else {
                $reporter->line('Skipped capell:html-cache:clear; command is not available');
            }
        }

        if (in_array('config', $selected, true)) {
            $passed = $this->clearCache($reporter, 'config:clear') && $passed;
        }

        if (in_array('views', $selected, true)) {
            return $this->clearCache($reporter, 'view:clear') && $passed;
        }

        return $passed;
    }

    private function clearCache(UpgradeReporter $reporter, string $command): bool
    {
        $exitCode = $reporter->callCommand($command);

        if ($exitCode !== Command::SUCCESS) {
            $reporter->error(sprintf('%s failed with exit code %d.', $command, $exitCode));

            return false;
        }

        return true;
    }

    /**
     * Under Testbench the application boots from a skeleton whose cached package manifests the
     * test harness relies on. Match the skeleton by name rather than by its vendor path, because
     * parallel test processes boot from per-process copies of it.
     */
    private function shouldSkipOptimizeClearForTestbench(): bool
    {
        return app()->runningUnitTests()
            && str_contains(app()->bootstrapPath(), 'testbench');
    }
}
