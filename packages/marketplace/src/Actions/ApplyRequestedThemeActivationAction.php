<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ThemeManifestKey;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Do the thing the "Activate after install" checkbox promised.
 *
 * The checkbox is recorded on the theme install intent at request time, because
 * the request and the install happen in different processes minutes apart. This
 * action is the other half: once the install has actually succeeded, it reads
 * that recorded intent and puts the theme live through the same
 * ApplyMarketplaceThemeToSitesAction the theme page uses, so there is one
 * activation path rather than two.
 *
 * It returns null rather than throwing for every "no" answer — not a theme, no
 * intent, the operator did not ask, the theme key cannot be resolved — because
 * the caller runs it after an install has already succeeded and none of those
 * are reasons to retract a good install.
 */
final class ApplyRequestedThemeActivationAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt): ?Theme
    {
        if ($attempt->kind !== ExtensionKind::Theme->value) {
            return null;
        }

        $intent = MarketplaceInstallIntent::query()
            ->where('composer_name', $attempt->composer_name)
            ->where('kind', ExtensionKind::Theme->value)
            ->first();

        if (! $intent instanceof MarketplaceInstallIntent) {
            return null;
        }

        $requested = data_get(
            $intent->metadata ?? [],
            'acquisition.' . RecordThemeInstallIntentAction::ACTIVATE_AFTER_INSTALL,
        );

        if ($requested !== true) {
            return null;
        }

        $themeKey = $this->themeKeyFor($attempt->composer_name);

        if ($themeKey === '') {
            return null;
        }

        // Workers have no request actor. Resolve the initiating user and take
        // a fresh membership snapshot; a missing user must never inherit another
        // request's access or turn into unrestricted installation access.
        $actor = ResolveMarketplaceInstallAttemptUserAction::run($attempt);
        $access = SiteAccess::forActor($actor instanceof Authenticatable ? $actor : null, acrossAssignedSites: true);

        return ApplyMarketplaceThemeToSitesAction::run(
            themeKey: $themeKey,
            themeName: $intent->extension_name !== '' ? $intent->extension_name : $attempt->extension_name,
            siteId: null,
            access: $access,
        );
    }

    /**
     * The registry is the authority when the package is present — a theme may
     * declare a themeKey that does not match its package name — and the
     * package-name derivation is the fallback for the window where the install
     * has completed but this process has not seen the manifest yet.
     */
    private function themeKeyFor(string $composerName): string
    {
        $manifest = CapellCore::hasPackage($composerName)
            ? CapellCore::getPackage($composerName)->manifest
            : null;

        return $manifest instanceof CapellManifestData
            ? ThemeManifestKey::resolve($manifest)
            : ThemeManifestKey::fromPackageName($composerName);
    }
}
