<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Activity\PruneActivityBucketsAction;
use Illuminate\Console\Command;
use Throwable;

final class PruneActivityBucketsCommand extends Command
{
    protected $signature = 'capell:activity:prune {--days= : Retention window from 1 to 7 days} {--pretend : Report rows without deleting them}';

    protected $description = 'Prune expired privacy-first activity buckets.';

    public function handle(PruneActivityBucketsAction $action): int
    {
        try {
            $days = $this->option('days');
            $days = is_string($days) && $days !== '' ? (int) $days : null;
            $count = $action->execute($days, (bool) $this->option('pretend'));
            $this->info(sprintf('%s %d activity bucket row(s).', $this->option('pretend') ? 'Would prune' : 'Pruned', $count));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
