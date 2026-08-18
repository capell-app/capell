<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Activity\PruneActivityVisitorsAction;
use Illuminate\Console\Command;
use Throwable;

final class PruneActivityVisitorsCommand extends Command
{
    protected $signature = 'capell:activity:prune-visitors {--days= : Retention window from 14 to 400 days} {--pretend : Report rows without deleting them}';

    protected $description = 'Prune expired privacy-first visitor-day markers.';

    public function handle(PruneActivityVisitorsAction $action): int
    {
        try {
            $days = $this->option('days');
            $days = is_string($days) && $days !== '' ? (int) $days : null;
            $count = $action->execute($days, (bool) $this->option('pretend'));
            $this->info(sprintf('%s %d visitor row(s).', $this->option('pretend') ? 'Would prune' : 'Pruned', $count));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
