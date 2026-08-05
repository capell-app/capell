<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Data\PackageData;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Exception;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether the theme a package ships is still in use somewhere.
 *
 * Extracted from UninstallPackageAction so a caller that has to decide whether
 * to *offer* an uninstall asks the same question the uninstall answers when it
 * refuses one. Queueing an uninstall that the lifecycle will reject minutes
 * later, on a worker, with a failed attempt and a rollback, is strictly worse
 * than declining it while the operator is still looking at the screen — and the
 * only way both answers stay the same is for there to be one of them.
 */
final class ActiveThemeUninstallGuard
{
    /**
     * @throws Exception when the theme is still in use
     */
    public function assert(PackageData $package): void
    {
        $reason = $this->refusalReason($package);

        if ($reason === null) {
            return;
        }

        throw new Exception($reason);
    }

    /**
     * The refusal as a value, so readiness and preflight surfaces can describe
     * it without provoking it.
     */
    public function refusalReason(PackageData $package): ?string
    {
        $themeKey = $package->getThemeKey();

        if ($themeKey === null) {
            return null;
        }

        $activeGlobally = app()->bound(ThemeStudioSettings::class)
            && resolve(ThemeStudioSettings::class)->activeTheme === $themeKey;
        $siteCount = Site::query()->whereHas(
            'theme',
            fn (Builder $themeQuery): Builder => $themeQuery->where('key', $themeKey),
        )->count();
        $layoutCount = Layout::query()->whereHas(
            'theme',
            fn (Builder $themeQuery): Builder => $themeQuery->where('key', $themeKey),
        )->count();

        if (! $activeGlobally && $siteCount === 0 && $layoutCount === 0) {
            return null;
        }

        return sprintf(
            "Theme package '%s' cannot be uninstalled while theme '%s' is in use (%d site(s), %d layout(s), global active theme: %s). Assign another installed theme to every site and layout and switch the global active theme first.",
            $package->name,
            $themeKey,
            $siteCount,
            $layoutCount,
            $activeGlobally ? 'yes' : 'no',
        );
    }
}
