<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

it('distinguishes otherwise identical site domains by port', function (): void {
    $site = new Site(['name' => 'Ports']);
    $first = new SiteDomain([
        'domain' => 'localhost',
        'scheme' => 'http',
        'port' => 8081,
        'status' => true,
    ]);
    $second = new SiteDomain([
        'domain' => 'localhost',
        'scheme' => 'http',
        'port' => 8082,
        'status' => true,
    ]);
    $site->setRelation('siteDomains', collect([$first, $second]));

    $resolution = ResolveSiteDomainAction::run(
        SiteRequestTargetData::fromUrl('http://localhost:8082/about'),
        collect([$site]),
    );

    expect($resolution)->not->toBeNull()
        ->and($resolution?->siteDomain)->toBe($second)
        ->and($resolution?->effectiveOrigin->rootUrl())->toBe('http://localhost:8082')
        ->and($resolution?->relativePath)->toBe('/about');
});

it('resolves wildcard domains repeatedly without mutating cached candidates', function (): void {
    $site = new Site(['name' => 'Wildcard']);
    $wildcard = new SiteDomain([
        'domain' => null,
        'scheme' => null,
        'port' => 8081,
        'path' => '/en',
        'status' => true,
    ]);
    $site->setRelation('siteDomains', collect([$wildcard]));
    $sites = collect([$site]);

    $first = ResolveSiteDomainAction::run(
        SiteRequestTargetData::fromUrl('http://first.test:8081/en/about'),
        $sites,
    );
    $second = ResolveSiteDomainAction::run(
        SiteRequestTargetData::fromUrl('https://second.test:8081/en/contact'),
        $sites,
    );

    expect($first?->effectiveOrigin->rootUrl())->toBe('http://first.test:8081')
        ->and($first?->relativePath)->toBe('/about')
        ->and($second?->effectiveOrigin->rootUrl())->toBe('https://second.test:8081')
        ->and($second?->relativePath)->toBe('/contact')
        ->and($wildcard->getRawOriginal('domain'))->toBeNull()
        ->and($wildcard->getRawOriginal('scheme'))->toBeNull()
        ->and($wildcard->domain)->toBeNull();
});
