<?php

declare(strict_types=1);

use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\ParseUrlStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;

uses()->group('kernel');

it('normalizes index.php and leading/trailing slashes', function (): void {
    $state = new FrontendState;
    $request = Request::create('https://example.com/index.php');
    $work = new FrontendWork($request, $state);

    $step = resolve(ParseUrlStep::class);
    $result = $step->handle($work, fn (FrontendWork $w): FrontendWork => $w);

    expect($result)->toBe($work)
        ->and($state->effectiveUrl())->toBe('/');

    $request2 = Request::create('https://example.com/path/');
    $work2 = new FrontendWork($request2, new FrontendState);
    $step->handle($work2, fn (FrontendWork $w): FrontendWork => $w);

    expect($work2->state->effectiveUrl())->toBe('/path/');
});
