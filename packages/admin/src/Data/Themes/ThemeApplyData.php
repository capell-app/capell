<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Capell\Admin\Enums\Themes\ThemeActivationScope;
use Spatie\LaravelData\Data;

final class ThemeApplyData extends Data
{
    /**
     * @param  list<int>  $siteIds
     */
    public function __construct(
        public int $themeId,
        public ThemeActivationScope $scope,
        public array $siteIds = [],
    ) {}

    public function toSetActiveThemeForSitesData(): SetActiveThemeForSitesData
    {
        return new SetActiveThemeForSitesData(
            themeId: $this->themeId,
            scope: $this->scope,
            siteIds: $this->siteIds,
        );
    }
}
