<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class ApplyMarketplaceThemeToSitesAction
{
    use AsAction;

    public function handle(string $themeKey, string $themeName, ?int $siteId = null): Theme
    {
        return DB::transaction(function () use ($themeKey, $themeName, $siteId): Theme {
            $theme = CreateThemeAction::run(
                key: $themeKey,
                name: $themeName,
                defaultColors: true,
            );

            $theme->forceFill(['status' => true])->save();

            $siteIds = Site::query()
                ->when(
                    $siteId !== null,
                    fn (Builder $query): Builder => $query->whereKey($siteId),
                )
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($siteIds !== []) {
                Site::query()
                    ->whereKey($siteIds)
                    ->update(['theme_id' => $theme->getKey()]);

                event(new FrontendSurrogateKeysInvalidated(
                    array_map(fn (int $affectedSiteId): string => 'site-' . $affectedSiteId, $siteIds),
                ));
            }

            if ($siteId === null) {
                Theme::query()
                    ->whereKeyNot($theme->getKey())
                    ->update(['default' => false]);

                $theme->forceFill(['default' => true])->save();
            }

            return $theme->refresh();
        });
    }
}
