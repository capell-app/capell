<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Diagnostics\BuildThemeDoctorReportAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckOutcome;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class ThemeDoctorCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:theme:doctor
        {theme : Theme key to inspect}
        {--path= : Theme package path, defaults to packages/{theme}}
        {--json : Output a machine-readable JSON report}';

    protected $description = 'Run theme-specific Capell diagnostics.';

    public function handle(): int
    {
        if (! $this->option('json')) {
            $this->writeCommandIntro('run Capell theme diagnostics', $this->enabledOptionDetails([
                'path' => 'a custom theme path',
            ]));
        }

        $report = BuildThemeDoctorReportAction::run(
            theme: (string) $this->argument('theme'),
            path: is_string($this->option('path')) ? $this->option('path') : null,
        );

        if ($this->option('json')) {
            $this->line(JsonCodec::encode($report->toArray(), JSON_PRETTY_PRINT));

            return $report->passed() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
        }

        $this->outputReport($report);

        return $report->passed() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }

    private function outputReport(DoctorReportData $report): void
    {
        $this->newLine();
        $this->line('<fg=blue;options=bold>Capell Theme Health Check</>');
        $this->newLine();

        $report->checks->each(function (DoctorCheckResultData $check): void {
            $this->outputCheckResult($check);
        });
        $this->newLine();

        if (! $report->passed()) {
            $this->error((string) __('capell-core::health.doctor.theme_failed'));

            return;
        }

        $report->warningCount() > 0
            ? $this->info((string) __('capell-core::health.doctor.theme_passed_with_warnings', ['count' => $report->warningCount()]))
            : $this->info((string) __('capell-core::health.doctor.theme_passed'));
    }

    private function outputCheckResult(DoctorCheckResultData $check): void
    {
        $outcome = $check->outcome();
        $icon = match (true) {
            $outcome === DoctorCheckOutcome::Passed => '<fg=green>✓</>',
            $outcome === DoctorCheckOutcome::Warning && $check->severity === DoctorCheckSeverity::Info => '<fg=blue>i</>',
            $outcome === DoctorCheckOutcome::Warning => '<fg=yellow>!</>',
            default => '<fg=red>✗</>',
        };
        $colour = match ($outcome) {
            DoctorCheckOutcome::Passed => 'green',
            DoctorCheckOutcome::Warning => $check->severity === DoctorCheckSeverity::Info ? 'blue' : 'yellow',
            DoctorCheckOutcome::Failed => 'red',
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
