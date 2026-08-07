<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Metrics\PruneMetricDailyRollupsAction;
use Illuminate\Console\Command;
use Throwable;

final class PruneMetricDailyRollupsCommand extends Command
{
    protected $signature = 'capell:metrics:prune {--days= : Retention window in days} {--pretend : Report rows without deleting them}';

    protected $description = 'Prune expired daily metric rollups and their provenance runs.';

    public function handle(PruneMetricDailyRollupsAction $action): int
    {
        try {
            $days = $this->option('days');
            $days = is_string($days) && $days !== '' ? (int) $days : null;
            $count = $action->execute($days, (bool) $this->option('pretend'));
            $this->info(sprintf('%s %d daily metric rollup row(s).', $this->option('pretend') ? 'Would prune' : 'Pruned', $count));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
