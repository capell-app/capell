<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Upgrade\BuildUpgradeReadinessReportAction;
use Capell\Core\Actions\Upgrade\CreateUpgradeRunAction;
use Capell\Core\Actions\Upgrade\MarkUpgradeRunFinishedAction;
use Capell\Core\Actions\Upgrade\ReportCapellUpgradeDryRunAction;
use Capell\Core\Actions\Upgrade\RunCapellUpgradeAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Support\Upgrade\AggregateUpgradeReporter;
use Capell\Core\Support\Upgrade\ConsoleUpgradeReporter;
use Capell\Core\Support\Upgrade\DatabaseUpgradeReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class UpgradeCommand extends Command
{
    use DescribesCommandOptions;

    protected $description = 'Upgrade Capell: publish+run migrations, execute pending steps, record versions.';

    protected $signature = 'capell:upgrade
        {--dry-run : Show the plan without making any changes}
        {--force : Skip interactive confirmations}
        {--force-downgrade : Proceed even if Composer version is older than the ledger}
        {--no-clear-cache : Do not clear caches after upgrade}
        {--caches= : Comma-separated list of caches to clear (all, page, config, views)}
        {--skip-migrations : Do not publish or run migrations}
        {--skip-steps : Do not run upgrade steps}
        {--only-migrations : Run migration phase only}
        {--only-steps : Run upgrade steps phase only}
        {--force-step=* : Re-run a specific step id even if already applied}';

    public function handle(): int
    {
        $this->writeCommandIntro('upgrade Capell', $this->upgradeIntroDetails());

        $options = $this->upgradeRunOptions();
        $consoleReporter = new ConsoleUpgradeReporter($this);

        if ($options->dryRun) {
            return ReportCapellUpgradeDryRunAction::run($consoleReporter);
        }

        $run = $this->createDurableRun($options);
        $reporter = $run instanceof UpgradeRun
            ? new AggregateUpgradeReporter($consoleReporter, new DatabaseUpgradeReporter($run))
            : $consoleReporter;

        try {
            $exitCode = RunCapellUpgradeAction::run($options, $reporter);
        } catch (Throwable $throwable) {
            if ($run instanceof UpgradeRun) {
                MarkUpgradeRunFinishedAction::run(
                    run: $run->refresh(),
                    status: UpgradeRunStatus::Failed,
                    message: $throwable->getMessage(),
                );
            }

            throw $throwable;
        }

        if ($run instanceof UpgradeRun) {
            MarkUpgradeRunFinishedAction::run(
                run: $run->refresh(),
                status: $exitCode === self::SUCCESS ? UpgradeRunStatus::Succeeded : UpgradeRunStatus::Failed,
                message: $exitCode === self::SUCCESS
                    ? 'Capell upgrade completed successfully.'
                    : sprintf('Capell upgrade failed with exit code %d.', $exitCode),
            );
        }

        return $exitCode;
    }

    /**
     * @return array<int, string>
     */
    private function upgradeIntroDetails(): array
    {
        $details = $this->enabledOptionDetails([
            'dry-run' => 'a dry run',
            'force' => 'confirmation skipped',
            'force-downgrade' => 'downgrade protection bypassed',
            'no-clear-cache' => 'cache clearing skipped',
            'skip-migrations' => 'migrations skipped',
            'skip-steps' => 'upgrade steps skipped',
            'only-migrations' => 'migrations only',
            'only-steps' => 'upgrade steps only',
        ]);

        if ($this->option('caches')) {
            $details[] = 'selected caches';
        }

        if ($this->option('force-step') !== []) {
            $details[] = 'forced upgrade steps';
        }

        return $details;
    }

    private function upgradeRunOptions(): UpgradeRunOptions
    {
        return new UpgradeRunOptions(
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force'),
            forceDowngrade: (bool) $this->option('force-downgrade'),
            noClearCache: (bool) $this->option('no-clear-cache'),
            skipMigrations: (bool) $this->option('skip-migrations'),
            skipSteps: (bool) $this->option('skip-steps'),
            onlyMigrations: (bool) $this->option('only-migrations'),
            onlySteps: (bool) $this->option('only-steps'),
            caches: $this->selectedCaches(),
            forceStepIds: array_values(array_filter($this->option('force-step'), is_string(...))),
            interactive: $this->input->isInteractive(),
        );
    }

    private function createDurableRun(UpgradeRunOptions $options): ?UpgradeRun
    {
        if (! Schema::hasTable('capell_upgrade_runs') || ! Schema::hasTable('capell_upgrade_run_events')) {
            return null;
        }

        return CreateUpgradeRunAction::run(
            options: $options,
            readiness: BuildUpgradeReadinessReportAction::run(),
            status: UpgradeRunStatus::Running,
            manualCommands: [
                'php artisan capell:upgrade --force --no-clear-cache --dry-run',
                'php artisan capell:upgrade --force --no-clear-cache',
            ],
            userId: null,
        );
    }

    /**
     * @return list<string>
     */
    private function selectedCaches(): array
    {
        $cachesOption = $this->option('caches');

        if (! is_string($cachesOption) || $cachesOption === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $cachesOption))));
    }
}
