<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Packages\TrustedCorePackages;

/**
 * Paid markers. A lifecycle or registered package paid flag, verified paid
 * manifest or signed catalogue entry takes precedence over free proof. Paid
 * catalogue namespaces survive a Composer rename while the package still
 * claims the original code identity.
 *
 * Reserved identities. Stripping a manifest from code under capell-app or Capell\
 * leaves it protected. Renaming out of both admits unsigned code if it has no
 * other paid marker. Only the catalogue's paid namespaces catch that rename
 * when the package still claims a listed identity.
 *
 * Release binding. Copying free proof to another package, version or reference
 * fails verification. Editing is_paid breaks the signature. An invalid file
 * blocks Capell admission even under an independent identity.
 *
 * Local development. The allowlist can exempt independent packages in lifecycle
 * checks. A paid marker, including a persisted paid flag, takes precedence.
 */
final class ExtensionCommercialPolicy
{
    public function __construct(
        private readonly CommercialCatalogueStore $catalogue,
        private readonly SignedPackageLicenceManifest $licences,
        private readonly InstalledPackageCodeIdentity $code = new InstalledPackageCodeIdentity,
    ) {}

    public function clear(): void
    {
        $this->catalogue->clear();
        $this->licences->clear();
        $this->code->clear();
    }

    /**
     * Issuer enforcement is on whenever Core carries a committed issuer key. Configuration and
     * APP_ENV can neither add a key nor switch enforcement off.
     */
    public function enforcing(): bool
    {
        // Read per call: the policy can be built during provider loading, before a binding
        // for the keys is final, and must not keep a stale copy.
        return resolve(TrustedMarketplaceIssuerKeys::class)->all() !== [];
    }

    public function requiresAuthorisation(string $name, ?CapellExtension $extension = null): bool
    {
        if (! $this->enforcing()) {
            return ! TrustedCorePackages::contains($name)
                && ($extension?->is_paid_marketplace_extension === true || $this->registeredPackageIsPaid($name));
        }

        if ($this->hasPaidMarker($name, $extension)) {
            return true;
        }

        return match ($this->licences->state($name)) {
            PackageLicenceManifestState::Paid, PackageLicenceManifestState::Invalid => true,
            PackageLicenceManifestState::Free => false,
            PackageLicenceManifestState::Absent => ! TrustedCorePackages::contains($name) && $this->isReserved($name),
        };
    }

    public function hasInvalidLicenceManifest(string $name): bool
    {
        return $this->enforcing() && $this->licences->state($name) === PackageLicenceManifestState::Invalid;
    }

    /**
     * A named exemption for developing a package locally. Paid markers always take precedence.
     */
    public function isLocalDevelopment(string $name, ?CapellExtension $extension = null): bool
    {
        if (! $this->isNamedLocalDevelopment($name)) {
            return false;
        }

        if (! $this->enforcing()) {
            return true;
        }

        return ! $this->hasPaidMarker($name, $extension)
            && ! $this->isReserved($name)
            && $this->licences->state($name) !== PackageLicenceManifestState::Invalid;
    }

    /**
     * Whether any of these classes sits under a namespace the signed catalogue lists as paid.
     *
     * @param  iterable<string>  $classes
     * @param  list<string>|null  $paid
     */
    public function classesArePaid(iterable $classes, ?array $paid = null): bool
    {
        if ($paid === null) {
            if (! $this->enforcing()) {
                return false;
            }

            $paid = $this->catalogue->paidNamespaces();
        }

        foreach ($classes as $class) {
            $normalised = strtolower(ltrim($class, '\\'));
            foreach ($paid as $namespace) {
                if (str_starts_with($normalised, $namespace)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the installed package's autoload namespaces or provider classes fall under a
     * namespace the signed catalogue lists as paid, whatever the package is called. Only the
     * issuer's list can make this true, so declaring extra namespaces can never make a package
     * free; it can only make it paid.
     */
    public function claimsPaidCode(string $name): bool
    {
        if (! $this->enforcing()) {
            return false;
        }

        $paid = $this->catalogue->paidNamespaces();
        if ($paid === []) {
            return false;
        }

        $code = $this->code->for($name);
        if ($this->classesArePaid($code['classes'], $paid)) {
            return true;
        }

        // Core's own broad prefixes must never read as covering a paid namespace beneath them.
        $coversBroader = ! TrustedCorePackages::contains($name);
        foreach ($paid as $namespace) {
            foreach ($code['namespaces'] as $declared) {
                // A narrower prefix inside the paid namespace loads paid classes, and so does a
                // broader one covering it. A root mapping ('') is not counted as broader: every
                // package would match, so such packages are judged by their provider classes.
                if (str_starts_with($declared, $namespace)
                    || ($coversBroader && $declared !== '' && str_starts_with($namespace, $declared))) {
                    return true;
                }
            }

            foreach ($code['prefixes'] as $prefix) {
                if ($prefix !== '' && (str_starts_with($prefix, $namespace) || ($coversBroader && str_starts_with($namespace, $prefix)))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasPaidMarker(string $name, ?CapellExtension $extension): bool
    {
        // Renamed paid code takes precedence even under a trusted Core name.
        if ($this->claimsPaidCode($name)) {
            return true;
        }

        if ($extension?->is_paid_marketplace_extension === true) {
            return true;
        }

        if ($this->registeredPackageIsPaid($name)) {
            return true;
        }

        return $this->enforcing() && ($this->catalogue->classification($name) === true
            || $this->licences->state($name) === PackageLicenceManifestState::Paid);
    }

    private function registeredPackageIsPaid(string $name): bool
    {
        return app()->bound(CapellCoreManager::class) && CapellCore::hasPackage($name) && CapellCore::getPackage($name)->isPaid === true;
    }

    private function isNamedLocalDevelopment(string $name): bool
    {
        $packages = config('capell.extensions.local_development', []);

        return app()->environment('local') && is_array($packages) && in_array($name, $packages, true);
    }

    private function isReserved(string $name): bool
    {
        if (str_starts_with(strtolower($name), 'capell-app/')) {
            return true;
        }

        foreach ($this->code->for($name) as $identities) {
            foreach ($identities as $identity) {
                if (str_starts_with($identity, 'capell\\')) {
                    return true;
                }
            }
        }

        return false;
    }
}
