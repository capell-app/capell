<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Admin\Data\Themes\SetActiveThemeForSitesData;
use Capell\Admin\Enums\Themes\ThemeActivationScope;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Theme run(SetActiveThemeForSitesData $data)
 */
final class SetActiveThemeForSitesAction
{
    use AsFake;
    use AsObject;

    public function handle(SetActiveThemeForSitesData $data): Theme
    {
        return DB::transaction(function () use ($data): Theme {
            $theme = Theme::query()->findOrFail($data->themeId);

            $theme->forceFill(['status' => true])->save();

            if ($data->scope === ThemeActivationScope::Global) {
                Theme::query()
                    ->whereKeyNot($theme->getKey())
                    ->update(['default' => false]);

                $theme->forceFill(['default' => true])->save();
            }

            if ($data->scope === ThemeActivationScope::SelectedSites && $data->siteIds !== []) {
                /** @var EloquentCollection<int, Site> $sites */
                $sites = Site::query()
                    ->whereKey($data->siteIds)
                    ->get();

                $sites->each(function (Site $site) use ($theme): void {
                    $site->forceFill(['theme_id' => $theme->getKey()])->save();
                });

                event(new FrontendSurrogateKeysInvalidated($this->siteSurrogateKeys($sites->modelKeys())));
            }

            return $theme->refresh();
        });
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return list<string>
     */
    private function siteSurrogateKeys(array $siteIds): array
    {
        return array_values(array_map(fn (int $siteId): string => 'site-' . $siteId, $siteIds));
    }
}
