<?php

declare(strict_types=1);

namespace Capell\Marketplace\Console\Commands;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Marketplace\Actions\BuildMarketplaceOperationsDoctorReportAction;
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
            $this->line(JsonCodec::encode($report->toArray()));

            return $report->status === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report->checks as $check) {
            $this->line(sprintf('[%s] %s: %s', $check->passed ? 'OK' : 'FAIL', $check->label, $check->message));
        }

        return $report->status === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
