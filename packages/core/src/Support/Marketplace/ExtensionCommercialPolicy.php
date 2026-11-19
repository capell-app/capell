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
        private readonly InstalledPackageCodeIdentity $code = new InstalledPackageCodeIdentity,
    ) {}

    public function clear(): void
    {
        $this->catalogue->clear();
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
        // Checked before every name-based answer, the trusted Core list and a free listing
        // included: renaming a paid package in metadata does not rename its PHP classes.
        if ($this->claimsPaidCode($name)) {
            return true;
        }

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
        if ($this->catalogue->isListed($name) || $this->claimsPaidCode($name)) {
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

        return $this->catalogue->classification($name) !== true && ! $this->claimsPaidCode($name);
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
}
