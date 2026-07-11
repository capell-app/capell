<?php

declare(strict_types=1);

use Capell\Admin\Tests\Fixtures\Autoload\SiteDomainValidationHarness;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

it('rejects duplicate null domains with the same scheme and path', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => null,
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => 'https',
        'host' => null,
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('allows an explicit host when only a null domain fallback exists', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => null,
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => 'https',
        'host' => 'example.test',
        'path' => '/tenant',
    ]))->toBeTrue();
});

it('rejects the app host when a matching null domain fallback exists', function (): void {
    config(['app.url' => 'https://capell.test']);

    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => null,
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => 'https',
        'host' => 'capell.test',
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('rejects duplicate null schemes with the same host and path', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'example.test',
        'scheme' => null,
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => null,
        'host' => 'example.test',
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('rejects a specific scheme when a null scheme fallback exists', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'example.test',
        'scheme' => null,
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => 'https',
        'host' => 'example.test',
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('rejects a null scheme when a specific scheme exists for the same host and path', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'example.test',
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => null,
        'host' => 'example.test',
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('allows a null scheme when the path differs', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'example.test',
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => null,
        'host' => 'example.test',
        'path' => '/other',
    ]))->toBeTrue();
});
