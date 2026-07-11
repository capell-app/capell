<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Site\SiteDomainFinder;

it('finds the first enabled default domain across sites', function (): void {
    $sites = collect([
        Site::factory()->has(SiteDomain::factory()->state(['default' => false, 'status' => true]), 'siteDomains')->create(),
        Site::factory()->has(SiteDomain::factory()->state(['default' => true, 'status' => true]), 'siteDomains')->create(),
    ]);

    $found = SiteDomainFinder::firstEnabledDefault($sites);
    $found = expectPresent($found);

    expect($found)->not->toBeNull()
        ->and($found->default)->toBeTrue()
        ->and($found->status)->toBeTrue();
});

it('returns null when no enabled default domain exists', function (): void {
    $sites = collect([
        Site::factory()->has(SiteDomain::factory()->state(['default' => false, 'status' => true]), 'siteDomains')->create(),
    ]);

    expect(SiteDomainFinder::firstEnabledDefault($sites))->toBeNull();
});
