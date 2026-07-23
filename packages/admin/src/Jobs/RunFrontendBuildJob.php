<?php

declare(strict_types=1);

namespace Capell\Admin\Jobs;

use Capell\Core\Actions\RunNpmBuildAction;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunFrontendBuildJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string STATUS_KEY = 'capell.admin.frontend-build';

    public const int STATUS_TTL_SECONDS = 7200;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = self::STATUS_TTL_SECONDS;

    public function uniqueId(): string
    {
        return 'frontend-build';
    }

    public function handle(MultiNodeTopologyGuard $topologyGuard): void
    {
        $topologyGuard->assertNodeLocalOperationIsAllowed('Frontend asset build');

        Cache::put(self::STATUS_KEY, [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
        ], self::STATUS_TTL_SECONDS);

        RunNpmBuildAction::run();

        Cache::put(self::STATUS_KEY, [
            'status' => 'succeeded',
            'finished_at' => now()->toIso8601String(),
        ], self::STATUS_TTL_SECONDS);
    }

    public function failed(?Throwable $throwable): void
    {
        Cache::put(self::STATUS_KEY, [
            'status' => 'failed',
            'finished_at' => now()->toIso8601String(),
            'message' => 'Frontend asset build failed. Review the queue worker log for details.',
        ], self::STATUS_TTL_SECONDS);
    }
}
