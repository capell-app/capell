<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\Marketplace\NullExtensionEntitlements;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class CheckInstallPackageAccessAction
{
    use AsObject;

    public function __construct(private readonly ExtensionEntitlements $entitlements) {}

    public function handle(PackageData $package, string $siteUrl): ?ExtensionLicenceDecisionData
    {
        // An unsigned default cannot establish the current account's entitlement.
        if ($this->entitlements instanceof NullExtensionEntitlements || $package->slug === null) {
            return null;
        }

        $domain = parse_url($siteUrl, PHP_URL_HOST);
        if (! is_string($domain) || $domain === '') {
            return null;
        }

        try {
            return $this->entitlements->licenceDecision($package->slug, 'install', $domain);
        } catch (Throwable) {
            // Composer can still verify separately configured download credentials.
            return null;
        }
    }
}
