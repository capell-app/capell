<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;

it('remains a no-op compatibility step', function (): void {
    $domain = SiteDomain::factory()->state(['path' => '/en'])->make(['id' => 1]);
    $state = (new FrontendState)
        ->withDomain($domain)
        ->withRelativePath('/en/products')
        ->setEffectiveUrl('/en/products');
    $work = new FrontendWork(Request::create('https://example.com/en/products'), $state);

    // Resolved by string FQCN, and invoked by reflection, deliberately. The
    // step is @deprecated, so a NormalizeDomainPathStep::class reference would
    // emit classConstant.deprecatedClass and fail the hard-gated deprecations
    // check this suite exists alongside; reflection is then forced because
    // level 8 rejects ->handle() on a bare object. Do not "simplify" either
    // back — CI goes red with no obvious cause. Both go away with the class
    // itself in 2.0.
    $step = resolve('Capell\\Frontend\\Support\\Kernel\\Steps\\NormalizeDomainPathStep');
    $result = (new ReflectionMethod($step, 'handle'))->invoke($step, $work, fn (FrontendWork $w): FrontendWork => $w);

    expect($result)->toBe($work)
        ->and($state->relativePath())->toBe('/en/products')
        ->and($state->effectiveUrl())->toBe('/en/products');
});
