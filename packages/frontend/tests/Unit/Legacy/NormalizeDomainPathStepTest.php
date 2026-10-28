<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Tests\Support\LegacyAdapter;
use Illuminate\Http\Request;

it('remains a no-op compatibility step', function (): void {
    $domain = SiteDomain::factory()->state(['path' => '/en'])->make(['id' => 1]);
    $state = (new FrontendState)
        ->withDomain($domain)
        ->withRelativePath('/en/products')
        ->setEffectiveUrl('/en/products');
    $work = new FrontendWork(Request::create('https://example.com/en/products'), $state);

    $result = LegacyAdapter::invoke('Capell\\Frontend\\Support\\Kernel\\Steps\\NormalizeDomainPathStep', 'handle', $work, fn (FrontendWork $w): FrontendWork => $w);

    expect($result)->toBe($work)
        ->and($state->relativePath())->toBe('/en/products')
        ->and($state->effectiveUrl())->toBe('/en/products');
});
