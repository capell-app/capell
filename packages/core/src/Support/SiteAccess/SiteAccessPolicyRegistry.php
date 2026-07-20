<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteAccess;

use Capell\Core\Contracts\SiteAccessPolicyProvider;
use Capell\Core\Data\SiteAccessContextData;
use Capell\Core\Data\SiteAccessPolicyData;

final class SiteAccessPolicyRegistry
{
    /** @var array<string, SiteAccessPolicyProvider> */
    private array $providers = [];

    public function register(SiteAccessPolicyProvider $provider): self
    {
        $this->providers[$provider->key()] = $provider;

        return $this;
    }

    /** @return list<SiteAccessPolicyProvider> */
    public function providers(): array
    {
        return array_values($this->providers);
    }

    public function resolve(SiteAccessContextData $context): ?SiteAccessPolicyData
    {
        $policies = array_values(array_filter(array_map(
            static fn (SiteAccessPolicyProvider $provider): ?SiteAccessPolicyData => $provider->resolve($context),
            $this->providers(),
        )));

        if ($policies === []) {
            return null;
        }

        $activePolicies = array_values(array_filter(
            $policies,
            static fn (SiteAccessPolicyData $policy): bool => $policy->active,
        ));

        if ($activePolicies === []) {
            return new SiteAccessPolicyData(active: false);
        }

        $methods = $activePolicies[0]->methods;

        foreach (array_slice($activePolicies, 1) as $policy) {
            $methods = array_values(array_intersect($methods, $policy->methods));
        }

        return new SiteAccessPolicyData(
            active: true,
            methods: array_values(array_unique($methods)),
            revision: max(array_map(static fn (SiteAccessPolicyData $policy): int => $policy->revision, $activePolicies)),
            sources: array_values(array_unique(array_merge(...array_map(
                static fn (SiteAccessPolicyData $policy): array => $policy->sources,
                $activePolicies,
            )))),
            configurationAvailable: ! in_array(false, array_map(
                static fn (SiteAccessPolicyData $policy): bool => $policy->configurationAvailable,
                $activePolicies,
            ), true),
        );
    }
}
