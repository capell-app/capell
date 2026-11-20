<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * The only Composer-discovered provider for a protected release. Declare feature
 * providers in capell.json for Core to load after authorisation. Every first-party
 * paid release must adopt this bootstrap before Core pins the production issuer key.
 */
abstract class ProtectedReleaseBootstrapProvider extends ServiceProvider
{
    /** @return list<class-string<ServiceProvider>> */
    abstract protected function legacyProviders(): array;

    #[Override]
    final public function register(): void
    {
        // Discovery can run before Core has registered any container bindings.
        $keys = $this->app->bound(TrustedMarketplaceIssuerKeys::class)
            ? $this->app->make(TrustedMarketplaceIssuerKeys::class)
            : new TrustedMarketplaceIssuerKeys;

        if ($keys->all() !== []) {
            return;
        }

        $role = RuntimeRoleResolver::boundTo($this->app)->role();
        foreach (new RuntimeRoleProviderPolicy()->filterProviders($this->legacyProviders(), $role) as $provider) {
            $this->app->register($provider);
        }
    }
}
