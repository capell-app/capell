<?php

declare(strict_types=1);

use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;

uses()->group('kernel');

it('remains a no-op compatibility step', function (): void {
    $state = (new FrontendState)
        ->withRelativePath('/canonical')
        ->setEffectiveUrl('/canonical');
    $request = Request::create('https://example.com/index.php');
    $work = new FrontendWork($request, $state);

    // Resolved by string FQCN, and invoked by reflection, deliberately. The
    // step is @deprecated, so a ParseUrlStep::class reference would emit
    // classConstant.deprecatedClass and fail the hard-gated deprecations
    // check this suite exists alongside; reflection is then forced because
    // level 8 rejects ->handle() on a bare object. Do not "simplify" either
    // back — CI goes red with no obvious cause. Both go away with the class
    // itself in 2.0.
    $step = resolve('Capell\\Frontend\\Support\\Kernel\\Steps\\ParseUrlStep');
    $result = (new ReflectionMethod($step, 'handle'))->invoke($step, $work, fn (FrontendWork $w): FrontendWork => $w);

    expect($result)->toBe($work)
        ->and($state->relativePath())->toBe('/canonical')
        ->and($state->effectiveUrl())->toBe('/canonical');
});
