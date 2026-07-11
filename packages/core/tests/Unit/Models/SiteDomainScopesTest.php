<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

it('scopes enabled and default domains', function (): void {
    $enabledDefault = Site::factory()
        ->has(SiteDomain::factory()->state(['status' => true, 'default' => true]), 'siteDomains')
        ->create()
        ->siteDomains
        ->first();

    Site::factory()
        ->has(SiteDomain::factory()->state(['status' => false, 'default' => true]), 'siteDomains')
        ->create();

    Site::factory()
        ->has(SiteDomain::factory()->state(['status' => true, 'default' => false]), 'siteDomains')
        ->create();

    expect(SiteDomain::query()->enabled()->default()->pluck('id')->all())
        ->toBe([$enabledDefault->id]);
});
