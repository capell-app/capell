<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

beforeEach(function (): void {
    SiteDomain::factory()->createOne([
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => null,
        'status' => true,
    ]);
});

it('parses host from URL', function (): void {
    $result = expectPresent(ResolveSiteDomainAction::run(
        SiteRequestTargetData::fromUrl('https://example.com/path'),
        Site::query()->with('siteDomains')->get(),
    ));

    expect($result->siteDomain->domain)->toBe('example.com');
});

it('rejects malformed request URLs before resolution', function (): void {
    expect(fn (): SiteRequestTargetData => SiteRequestTargetData::fromUrl('not-a-url'))
        ->toThrow(InvalidArgumentException::class);
});
