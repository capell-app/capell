<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Models\HostLoadRollup;
use Capell\Core\Support\HostMonitor\HostLoadRetention;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Deletes hourly rollups older than the retention setting, a bounded batch at a time so one run never locks the table. */
final readonly class PruneHostLoadRollupsAction
{
    public const int BATCH = 1000;

    public const int MAXIMUM_BATCHES = 20;

    /** @return int Rows deleted. */
    public function handle(): int
    {
        try {
            if (! Schema::hasTable((new HostLoadRollup)->getTable())) {
                return 0;
            }

            $cutoff = now()->getTimestamp() - HostLoadRetention::days() * 86400;
            $deleted = 0;

            for ($batch = 0; $batch < self::MAXIMUM_BATCHES; $batch++) {
                $ids = HostLoadRollup::query()->where('hour_start', '<', $cutoff)->orderBy('id')->limit(self::BATCH)->pluck('id');

                if ($ids->isEmpty()) {
                    break;
                }

                $deleted += HostLoadRollup::query()->whereIn('id', $ids)->delete();
            }

            return $deleted;
        } catch (Throwable $throwable) {
            report($throwable);

            return 0;
        }
    }
}
