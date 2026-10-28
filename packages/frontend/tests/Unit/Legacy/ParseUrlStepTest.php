<?php

declare(strict_types=1);

use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Tests\Support\LegacyAdapter;
use Illuminate\Http\Request;

uses()->group('kernel');

it('remains a no-op compatibility step', function (): void {
    $state = (new FrontendState)
        ->withRelativePath('/canonical')
        ->setEffectiveUrl('/canonical');
    $request = Request::create('https://example.com/index.php');
    $work = new FrontendWork($request, $state);

    $result = LegacyAdapter::invoke('Capell\\Frontend\\Support\\Kernel\\Steps\\ParseUrlStep', 'handle', $work, fn (FrontendWork $w): FrontendWork => $w);

    expect($result)->toBe($work)
        ->and($state->relativePath())->toBe('/canonical')
        ->and($state->effectiveUrl())->toBe('/canonical');
});
