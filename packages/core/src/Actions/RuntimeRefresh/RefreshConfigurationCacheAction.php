<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsObject;

class RefreshConfigurationCacheAction
{
    use AsObject;

    public function __construct(
        private readonly Application $application,
        private readonly RunArtisanRuntimeRefreshStageAction $runArtisanStage,
    ) {}

    public function handle(): RuntimeRefreshStageResultData
    {
        if (! $this->application->configurationIsCached()) {
            return new RuntimeRefreshStageResultData(
                key: 'config',
                label: 'Laravel configuration cache',
                passed: true,
                message: 'Configuration was not cached, so its uncached mode was preserved.',
                skipped: true,
            );
        }

        return $this->runArtisanStage->handle('config', 'Laravel configuration cache', 'config:cache');
    }
}
