<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Diagnostics\BuildThemeDoctorReportAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
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

        $report->passed()
            ? $this->info('All theme checks passed.')
            : $this->error('One or more theme checks failed. See suggestions above.');
    }

    private function outputCheckResult(DoctorCheckResultData $check): void
    {
        $icon = $check->passed
            ? '<fg=green>✓</>'
            : match ($check->severity) {
                DoctorCheckSeverity::Info => '<fg=blue>i</>',
                DoctorCheckSeverity::Warning => '<fg=yellow>!</>',
                DoctorCheckSeverity::Critical => '<fg=red>✗</>',
            };
        $this->components->twoColumnDetail(
            sprintf('%s %s', $icon, $check->label),
            match (true) {
                $check->passed => '<fg=green>' . $check->message . '</>',
                $check->severity === DoctorCheckSeverity::Info => '<fg=blue>' . $check->message . '</>',
                $check->severity === DoctorCheckSeverity::Warning => '<fg=yellow>' . $check->message . '</>',
                default => '<fg=red>' . $check->message . '</>',
            },
        );

        if (! $check->passed && $check->remediation !== null && $check->remediation !== '') {
            $this->line(sprintf('  <fg=yellow>Fix:</> %s', $check->remediation));
        }
    }
}
