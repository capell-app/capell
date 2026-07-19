<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshResultData;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class RunRuntimeRefreshAction
{
    use AsObject;

    public function __construct(
        private readonly RunArtisanRuntimeRefreshStageAction $runArtisanStage,
        private readonly RefreshConfigurationCacheAction $refreshConfigurationCache,
        private readonly RefreshRouteCacheAction $refreshRouteCache,
        private readonly WarmRuntimeAction $warmRuntime,
        private readonly RunRuntimeDoctorAction $runDoctor,
    ) {}

    public function handle(): RuntimeRefreshResultData
    {
        /** @var Collection<int, RuntimeRefreshStageResultData> $stages */
        $stages = collect();

        $this->runStage($stages, 'packages', 'Capell package cache', fn (): RuntimeRefreshStageResultData => $this->runArtisanStage->handle('packages', 'Capell package cache', 'capell:package-cache'));
        $this->runStage($stages, 'views', 'Compiled views', fn (): RuntimeRefreshStageResultData => $this->runArtisanStage->handle('views', 'Compiled views', 'view:clear'));
        $this->runStage($stages, 'config', 'Laravel configuration cache', fn (): RuntimeRefreshStageResultData => $this->refreshConfigurationCache->handle());
        $this->runStage($stages, 'routes', 'Laravel route cache', fn (): RuntimeRefreshStageResultData => $this->refreshRouteCache->handle());
        $this->runStage($stages, 'warm', 'Critical runtime pages', fn (): RuntimeRefreshStageResultData => $this->warmRuntime->handle());
        $this->runStage($stages, 'doctor', 'Capell Doctor', fn (): RuntimeRefreshStageResultData => $this->runDoctor->handle());

        return new RuntimeRefreshResultData($stages);
    }

    /**
     * @param  Collection<int, RuntimeRefreshStageResultData>  $stages
     * @param  callable(): RuntimeRefreshStageResultData  $stage
     */
    private function runStage(Collection $stages, string $key, string $label, callable $stage): void
    {
        try {
            $stages->push($stage());
        } catch (Throwable $throwable) {
            $stages->push(new RuntimeRefreshStageResultData(
                key: $key,
                label: $label,
                passed: false,
                message: $throwable->getMessage(),
            ));
        }
    }
}
