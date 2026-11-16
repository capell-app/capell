<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Packages\TrustedCorePackages;

/**
 * Decides whether a package needs Marketplace authorisation. Local state may raise the
 * requirement but never lower it: only the issuer-signed catalogue can classify a package free.
 */
final class ExtensionCommercialPolicy
{
    public function __construct(
        private readonly CommercialCatalogueStore $catalogue,
        private readonly TrustedMarketplaceIssuerKeys $issuerKeys,
    ) {}

    public function clear(): void
    {
        $this->catalogue->clear();
    }

    /**
     * Issuer enforcement is on whenever Core carries a committed issuer key. Configuration and
     * APP_ENV can neither add a key nor switch enforcement off.
     */
    public function enforcing(): bool
    {
        return $this->issuerKeys->all() !== [];
    }

    public function requiresAuthorisation(string $name, ?CapellExtension $extension = null): bool
    {
        if (TrustedCorePackages::contains($name)) {
            return false;
        }

        if ($extension?->is_paid_marketplace_extension === true
            || (CapellCore::hasPackage($name) && CapellCore::getPackage($name)->isPaid === true)) {
            return true;
        }

        if (! $this->enforcing()) {
            return false;
        }

        // Without a verified catalogue nothing can prove a package free: fail closed rather
        // than trust Composer metadata or database flags the site owner can edit.
        if ($this->catalogue->current() === null) {
            return true;
        }

        $classification = $this->catalogue->classification($name);
        if ($classification !== null) {
            return $classification;
        }

        // An unlisted Capell-vendor package is never assumed free.
        return str_starts_with(strtolower($name), 'capell-app/');
    }

    /** Whether the issuer recognises the package as a commercial identity at all. */
    public function isRecognised(string $name): bool
    {
        if ($this->catalogue->isListed($name)) {
            return true;
        }

        return str_starts_with(strtolower($name), 'capell-app/');
    }

    /**
     * A named exemption for developing a package locally. APP_ENV is owner controlled, so an
     * exemption never covers a package the issuer classifies as paid while enforcement is on.
     */
    public function isLocalDevelopment(string $name): bool
    {
        $packages = config('capell.extensions.local_development', []);
        if (! app()->environment('local') || ! is_array($packages) || ! in_array($name, $packages, true)) {
            return false;
        }

        if (! $this->enforcing()) {
            return true;
        }

        return $this->catalogue->classification($name) !== true;
    }
}
