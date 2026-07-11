<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Actions\RepairPageUrlsMissingSiteDomainsAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Illuminate\Console\Command;
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
        {--install-summary : Output the installer-focused health summary}
        {--repair-page-url-domains : Create or restore missing site domains for page URLs before checking health}
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
        if (! $this->option('json')) {
            $this->writeCommandIntro('run Capell health checks', $this->enabledOptionDetails([
                'install-summary' => 'the installer health summary',
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

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $report->passed() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
        }

        $this->outputReport($report);

        return $report->passed() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }

    private function outputReport(DoctorReportData $report): void
    {
        $this->newLine();
        $this->line($this->option('install-summary')
            ? '<fg=blue;options=bold>Capell Install Health Summary</>'
            : '<fg=blue;options=bold>Capell Health Check</>');
        $this->newLine();

        $report->checks->each(fn (DoctorCheckResultData $check): mixed => $this->outputCheckResult($check));

        $this->newLine();

        if ($report->passed()) {
            $this->info('All checks passed.');

            return;
        }

        $this->error('One or more checks failed. See suggestions above.');
    }

    private function outputCheckResult(DoctorCheckResultData $check): void
    {
        $icon = $check->passed ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $message = $check->message;

        if (! $check->passed && $check->remediation !== null && $check->remediation !== '') {
            $message .= ' ' . $check->remediation;
        }

        $this->components->twoColumnDetail(
            sprintf('%s %s', $icon, $check->label),
            $check->passed ? '<fg=green>' . $message . '</>' : '<fg=red>' . $message . '</>',
        );
    }
}
