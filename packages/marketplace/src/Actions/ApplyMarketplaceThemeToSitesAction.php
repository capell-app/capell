<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ApplyMarketplaceThemeToSitesAction
{
    use AsFake;
    use AsObject;

    public function handle(string $themeKey, string $themeName, ?int $siteId = null, ?SiteAccess $access = null): Theme
    {
        $access ??= SiteAccess::current();
        $sites = $access->query(Site::class)->when($siteId !== null, fn (Builder $query): Builder => $query->whereKey($siteId));
        throw_unless(($siteId === null && $access->isGlobal()) || $sites->exists(), AuthorizationException::class);

        return DB::transaction(function () use ($themeKey, $themeName, $siteId, $access): Theme {
            $theme = CreateThemeAction::run(
                key: $themeKey,
                name: $themeName,
                defaultColors: true,
                default: $access->isGlobal() ? null : Theme::query()->where('key', $themeKey)->value('default') === true,
            );

            $theme->forceFill(['status' => true])->save();

            $siteIds = $access->query(Site::class)
                ->when(
                    $siteId !== null,
                    fn (Builder $query): Builder => $query->whereKey($siteId),
                )
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($siteIds !== []) {
                $access->query(Site::class)
                    ->whereKey($siteIds)
                    ->update(['theme_id' => $theme->getKey()]);

                event(new FrontendSurrogateKeysInvalidated(
                    array_map(fn (int $affectedSiteId): string => 'site-' . $affectedSiteId, $siteIds),
                ));
            }

            if ($siteId === null && $access->isGlobal()) {
                Theme::query()
                    ->whereKeyNot($theme->getKey())
                    ->update(['default' => false]);

                $theme->forceFill(['default' => true])->save();
            }

            return $theme->refresh();
        });
    }
}
