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

it('resolves the default domain as the singular site domain', function (): void {
    $site = Site::factory()->create();

    SiteDomain::factory()->for($site)->create([
        'domain' => '127.0.0.1',
        'status' => true,
        'default' => false,
    ]);

    $defaultDomain = SiteDomain::factory()->for($site)->create([
        'domain' => 'capell.app',
        'status' => true,
        'default' => true,
    ]);

    expect($site->fresh()->siteDomain)->toBeInstanceOf(SiteDomain::class)
        ->and($site->fresh()->siteDomain?->is($defaultDomain))->toBeTrue();
});
