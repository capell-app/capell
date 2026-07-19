<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Cache;

use Capell\Admin\Jobs\RunFrontendBuildJob;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static bool run() */
final class QueueFrontendBuildAction
{
    use AsFake;
    use AsObject;

    public function handle(): bool
    {
        $queued = Cache::lock(RunFrontendBuildJob::STATUS_KEY . '.dispatch', 10)->get(function (): bool {
            $status = data_get(Cache::get(RunFrontendBuildJob::STATUS_KEY), 'status');

            if (in_array($status, ['queued', 'running'], true)) {
                return false;
            }

            Cache::put(RunFrontendBuildJob::STATUS_KEY, [
                'status' => 'queued',
                'queued_at' => now()->toIso8601String(),
            ], RunFrontendBuildJob::STATUS_TTL_SECONDS);

            dispatch(new RunFrontendBuildJob);

            return true;
        });

        return $queued === true;
    }
}
