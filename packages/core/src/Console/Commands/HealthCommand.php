<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Health\BuildHealthReportAction;
use Capell\Core\Data\Health\HealthCheckResultData;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Console\Command;

final class HealthCommand extends Command
{
    protected $signature = 'capell:health {--json : Output a machine-readable JSON report}';

    protected $description = 'Run bounded operational health checks.';

    public function handle(BuildHealthReportAction $action): int
    {
        $report = $action->handle();
        if ($this->option('json')) {
            $this->line(JsonCodec::encode($report->toArray(), JSON_PRETTY_PRINT));
        } else {
            $this->components->info('Capell operational health');
            foreach ($report->grouped() as $category => $checks) {
                $this->newLine();
                $this->line(sprintf('<options=bold>%s</>', ucfirst(str_replace(['-', '.'], ' ', $category))));
                foreach ($checks as $check) {
                    $this->components->twoColumnDetail($this->label($check), sprintf('%s (%d ms)', $check->summary, $check->durationMilliseconds));
                    if ($check->remediation !== null) {
                        $this->line('  Fix: ' . $check->remediation);
                    }
                }
            }

            $this->newLine();
            $this->line('Overall status: ' . $report->status()->value);
        }

        return $report->status()->failed() ? self::FAILURE : self::SUCCESS;
    }

    private function label(HealthCheckResultData $check): string
    {
        return sprintf('[%s/%s] %s', $check->status->value, $check->severity->value, $check->id);
    }
}
