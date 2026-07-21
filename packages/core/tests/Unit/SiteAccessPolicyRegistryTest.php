<?php

declare(strict_types=1);

use Capell\Core\Contracts\SiteAccessPolicyProvider;
use Capell\Core\Data\SiteAccessContextData;
use Capell\Core\Data\SiteAccessPolicyData;
use Capell\Core\Support\SiteAccess\SiteAccessPolicyRegistry;
use Illuminate\Http\Request;

function siteAccessProvider(string $key, ?SiteAccessPolicyData $policy): SiteAccessPolicyProvider
{
    return new readonly class($key, $policy) implements SiteAccessPolicyProvider
    {
        public function __construct(
            private string $providerKey,
            private ?SiteAccessPolicyData $policy,
        ) {}

        public function key(): string
        {
            return $this->providerKey;
        }

        public function resolve(SiteAccessContextData $context): ?SiteAccessPolicyData
        {
            return $this->policy;
        }
    };
}

it('preserves current behaviour when no provider supplies a policy', function (): void {
    $policy = (new SiteAccessPolicyRegistry)->resolve(new SiteAccessContextData(Request::create('/')));

    expect($policy)->toBeNull();
});

it('composes active providers restrictively', function (): void {
    $registry = (new SiteAccessPolicyRegistry)
        ->register(siteAccessProvider('first', new SiteAccessPolicyData(
            active: true,
            methods: ['shared_password', 'assigned_user'],
            revision: 2,
            sources: ['site'],
        )))
        ->register(siteAccessProvider('second', new SiteAccessPolicyData(
            active: true,
            methods: ['assigned_user', 'trusted_proxy'],
            revision: 7,
            sources: ['environment'],
            configurationAvailable: false,
        )));

    $policy = $registry->resolve(new SiteAccessContextData(Request::create('/')));

    expect($policy)->not->toBeNull()
        ->and($policy?->active)->toBeTrue()
        ->and($policy?->methods)->toBe(['assigned_user'])
        ->and($policy?->revision)->toBe(7)
        ->and($policy?->sources)->toBe(['site', 'environment'])
        ->and($policy?->configurationAvailable)->toBeFalse();
});

it('replaces providers with the same stable key', function (): void {
    $registry = (new SiteAccessPolicyRegistry)
        ->register(siteAccessProvider('access-gate', new SiteAccessPolicyData(active: true)))
        ->register(siteAccessProvider('access-gate', new SiteAccessPolicyData(active: false)));

    expect($registry->providers())->toHaveCount(1)
        ->and($registry->resolve(new SiteAccessContextData(Request::create('/')))?->active)->toBeFalse();
});
