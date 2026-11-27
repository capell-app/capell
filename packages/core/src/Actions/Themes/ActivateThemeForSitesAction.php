<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Themes;

use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Puts a theme live: creates or enables its theme record, points the given sites at it
 * and, when asked, makes it the default theme for new sites.
 *
 * Callers decide which sites the actor may change; this action does not check access.
 *
 * @method static Theme run(string $themeKey, string $themeName, list<int> $siteIds, bool $makeDefault, ?bool $default = null)
 */
final class ActivateThemeForSitesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<int>  $siteIds
     */
    public function handle(string $themeKey, string $themeName, array $siteIds, bool $makeDefault, ?bool $default = null): Theme
    {
        return DB::transaction(function () use ($themeKey, $themeName, $siteIds, $makeDefault, $default): Theme {
            // An existing theme keeps its colours and header/footer settings, which other sites share;
            // only a theme with no record yet gets the defaults.
            $theme = Theme::query()->where('key', $themeKey)->first() ?? CreateThemeAction::run(
                key: $themeKey,
                name: $themeName,
                defaultColors: true,
                default: $default,
            );

            $theme->forceFill(['status' => true])->save();

            if ($siteIds !== []) {
                Site::query()
                    ->whereKey($siteIds)
                    ->update(['theme_id' => $theme->getKey()]);

                event(new FrontendSurrogateKeysInvalidated(
                    array_map(fn (int $siteId): string => 'site-' . $siteId, $siteIds),
                ));
            }

            if ($makeDefault) {
                Theme::query()
                    ->whereKeyNot($theme->getKey())
                    ->update(['default' => false]);

                $theme->forceFill(['default' => true])->save();
            }

            return $theme->refresh();
        });
    }
}
