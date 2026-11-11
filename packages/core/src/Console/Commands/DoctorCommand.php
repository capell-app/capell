<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Actions\RepairPageUrlsMissingSiteDomainsAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckOutcome;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;

class DoctorCommand extends Command
{
    use DescribesCommandOptions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'capell:doctor
        {--json : Output a machine-readable JSON health report}
        {--connection= : Use an existing database connection for isolated verification}
        {--database= : Override that connection database name or path for isolated verification}
        {--install-summary : Output the installer-focused health summary}
        {--report-json= : Write the health report as JSON to this file}
        {--repair-page-url-domains : Create or restore missing site domains for page URLs before checking health}
        {--warnings : List only the checks that reported warnings}
        {--skip-package-doctors : Skip package-owned doctor commands when building the install summary}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run health checks on your Capell installation.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->applyDatabaseOverride();

        if (! $this->option('json')) {
            $this->writeCommandIntro('run Capell health checks', $this->enabledOptionDetails([
                'install-summary' => 'the installer health summary',
                'warnings' => 'only checks with warnings listed',
                'repair-page-url-domains' => 'missing page URL site domains repaired before checks',
                'skip-package-doctors' => 'package doctor checks skipped',
            ]));
        }

        if ($this->option('repair-page-url-domains')) {
            $repaired = RepairPageUrlsMissingSiteDomainsAction::run();

            if (! $this->option('json')) {
                $this->components->info(sprintf('Repaired %d page URL site domain pair(s).', $repaired));
            }
        }

        $output = $this->output;
        $report = BuildDoctorReportAction::run(
            installSummary: $this->option('install-summary'),
            includePackageDoctors: ! $this->option('skip-package-doctors'),
        );
        $this->setOutput($output);

        $reportPath = $this->option('report-json');
        if (is_string($reportPath) && $reportPath !== '') {
            throw_if(file_put_contents($reportPath, JsonCodec::encode($report->toArray(), JSON_PRETTY_PRINT)) === false, RuntimeException::class, 'Unable to write the health report.');
        }

        if ($this->option('json')) {
            $this->line(JsonCodec::encode($report->toArray(), JSON_PRETTY_PRINT));

            return $report->passed() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
        }

        $this->outputReport($report);

        return $report->passed() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }

    private function applyDatabaseOverride(): void
    {
        $connection = $this->option('connection');
        $database = $this->option('database');

        if ($connection === null && $database === null) {
            return;
        }

        throw_if(! is_string($connection) || $connection === ''
            || ! is_array(config('database.connections.' . $connection))
            || ! is_string($database) || $database === '', InvalidArgumentException::class, 'Isolated doctor verification requires a configured connection and database override.');

        config([
            'database.default' => $connection,
            'database.connections.' . $connection . '.database' => $database,
        ]);
        DB::purge($connection);
    }

    private function outputReport(DoctorReportData $report): void
    {
        $this->newLine();
        $this->line($this->option('install-summary')
            ? '<fg=blue;options=bold>Capell Install Health Summary</>'
            : '<fg=blue;options=bold>Capell Health Check</>');
        $this->newLine();

        $listed = $this->option('warnings') ? $report->warnings() : $report->checks;

        if ($this->option('warnings') && $listed->isEmpty()) {
            $this->components->info((string) __('capell-core::health.doctor.no_warnings_to_list'));
        }

        $listed->each(function (DoctorCheckResultData $check): void {
            $this->outputCheckResult($check);
        });

        $this->newLine();

        if (! $report->passed()) {
            $this->error((string) __('capell-core::health.doctor.failed'));
        } elseif ($report->warningCount() > 0) {
            $this->info((string) __('capell-core::health.doctor.passed_with_warnings'));
        } else {
            $this->info((string) __('capell-core::health.doctor.all_passed'));
        }

        // The listing already is the warnings, so only point at it from the full report.
        if ($report->warningCount() > 0 && ! $this->option('warnings')) {
            $this->line($report->warningSummary()->message());
        }
    }

    private function outputCheckResult(DoctorCheckResultData $check): void
    {
        $outcome = $check->outcome();
        $icon = match (true) {
            $outcome === DoctorCheckOutcome::Passed => '<fg=green>✓</>',
            $outcome === DoctorCheckOutcome::Failed => '<fg=red>✗</>',
            $check->severity === DoctorCheckSeverity::Info => '<fg=blue>i</>',
            default => '<fg=yellow>!</>',
        };
        $colour = match (true) {
            $outcome === DoctorCheckOutcome::Passed => 'green',
            $outcome === DoctorCheckOutcome::Failed => 'red',
            $check->severity === DoctorCheckSeverity::Info => 'blue',
            default => 'yellow',
        };
        $this->components->twoColumnDetail(
            sprintf('%s %s', $icon, $check->label),
            sprintf('<fg=%s>%s</>', $colour, $check->message),
        );

        if ($outcome !== DoctorCheckOutcome::Passed && $check->remediation !== null && $check->remediation !== '') {
            $this->line(sprintf('  <fg=yellow>Fix:</> %s', $check->remediation));
        }
    }
}
