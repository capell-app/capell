<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\NormalizeDomainPathStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;

it('strips domain path prefix from effective url', function (): void {
    $domain = SiteDomain::factory()->state([
        'path' => '/en',
    ])->make(['id' => 1]);

    $state = new FrontendState;
    $state->withDomain($domain);
    $state->setEffectiveUrl('/en/products');

    $work = new FrontendWork(Request::create('https://example.com/en/products'), $state);

    $step = resolve(NormalizeDomainPathStep::class);
    $result = $step->handle($work, fn (FrontendWork $w): FrontendWork => $w);

    expect($result)->toBe($work)
        ->and($state->effectiveUrl())->toBe('/products');
});

it('preserves the nested path when stripping a trailing index.php', function (): void {
    $domain = SiteDomain::factory()->state(['path' => '/en'])->make(['id' => 1]);
    $state = new FrontendState;
    $state->withDomain($domain);
    $state->setEffectiveUrl('/en/catalogue/index.php');

    $work = new FrontendWork(Request::create('https://example.com/en/catalogue/index.php'), $state);

    resolve(NormalizeDomainPathStep::class)
        ->handle($work, fn (FrontendWork $frontendWork): FrontendWork => $frontendWork);

    expect($state->effectiveUrl())->toBe('/catalogue/');
});
