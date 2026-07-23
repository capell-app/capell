<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Metrics\RollupMetricEventsAction;
use Illuminate\Console\Command;
use Throwable;

final class RollupMetricEventsCommand extends Command
{
    protected $signature = 'capell:metrics:rollup {--day= : UTC day to roll up in Y-m-d format}';

    protected $description = 'Roll up sampled metric events into daily metric points.';

    public function handle(RollupMetricEventsAction $action): int
    {
        try {
            $day = $this->option('day');
            $count = is_string($day) && $day !== ''
                ? $action->handle($day)
                : $action->handlePending();
            $this->info(sprintf('Rolled up %d metric event row(s).', $count));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
