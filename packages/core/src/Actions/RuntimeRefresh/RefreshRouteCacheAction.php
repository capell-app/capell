<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RefreshRouteCacheAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly Application $application,
        private readonly RunArtisanRuntimeRefreshStageAction $runArtisanStage,
    ) {}

    public function handle(): RuntimeRefreshStageResultData
    {
        if (! $this->application->routesAreCached()) {
            return new RuntimeRefreshStageResultData(
                key: 'routes',
                label: 'Laravel route cache',
                passed: true,
                message: 'Routes were not cached, so their uncached mode was preserved.',
                skipped: true,
            );
        }

        return $this->runArtisanStage->handle('routes', 'Laravel route cache', 'route:cache');
    }
}
