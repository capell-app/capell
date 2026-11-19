<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Spatie\LaravelData\Data;

final class DeferrableTasksStatusData extends Data
{
    /**
     * @param  list<string>  $tasks  Every task the host may defer.
     * @param  list<string>  $deferred  The listed tasks that skipped a run and have not yet been caught up.
     */
    public function __construct(
        public readonly array $tasks,
        public readonly array $deferred,
    ) {}

    public function enabled(): bool
    {
        return $this->tasks !== [];
    }
}
