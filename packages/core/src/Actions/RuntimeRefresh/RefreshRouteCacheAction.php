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

    public function handle(bool $rebuild = true): RuntimeRefreshStageResultData
    {
        // Laravel memoises bootstrap cache mode; activation must inspect the actual file.
        if (! ($rebuild ? $this->application->routesAreCached() : is_file($this->application->getCachedRoutesPath()))) {
            return new RuntimeRefreshStageResultData(
                key: 'routes',
                label: 'Laravel route cache',
                passed: true,
                message: 'Routes were not cached, so their uncached mode was preserved.',
                skipped: true,
            );
        }

        return $this->runArtisanStage->handle('routes', 'Laravel route cache', $rebuild ? 'route:cache' : 'route:clear');
    }
}
