<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\Themes\ActivateThemeForSitesAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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

        $siteIds = $sites
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return ActivateThemeForSitesAction::run(
            themeKey: $themeKey,
            themeName: $themeName,
            siteIds: $siteIds,
            makeDefault: $siteId === null && $access->isGlobal(),
            default: $access->isGlobal() ? null : Theme::query()->where('key', $themeKey)->value('default') === true,
        );
    }
}
