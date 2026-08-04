<?php

declare(strict_types=1);

namespace Capell\Marketplace\Console\Commands;

use Capell\Marketplace\Actions\BuildMarketplaceOperationsDoctorReportAction;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Illuminate\Console\Command;

final class MarketplaceDoctorCommand extends Command
{
    protected $signature = 'capell:marketplace:doctor
        {--json : Output the report as JSON}
        {--stale-after=15 : Minutes without a heartbeat before an active operation is stuck}';

    protected $description = 'Check Marketplace queued-operation health';

    public function handle(): int
    {
        $report = BuildMarketplaceOperationsDoctorReportAction::run(
            staleAfterMinutes: max(1, (int) $this->option('stale-after')),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR));

            return $report->status === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report->checks as $check) {
            $this->line(sprintf('[%s] %s: %s', $check->passed ? 'OK' : 'FAIL', $check->label, $check->message));

            if ($check->remediation !== null) {
                $this->line('       ' . $check->remediation);
            }
        }

        // Only worth saying when there is something to look up. A passing report
        // should end without homework.
        if ($report->status !== 'passed') {
            $this->line((string) __('capell-marketplace::marketplace.operations.doctor_docs_hint', [
                'path' => EvaluateMarketplaceEnvironmentReadinessAction::DOCS_PATH,
            ]));
        }

        return $report->status === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
