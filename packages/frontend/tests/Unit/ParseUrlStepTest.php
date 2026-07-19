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
    $result = $step->handle($work, fn (FrontendWork $frontendWork): FrontendWork => $frontendWork);

    expect($result)->toBe($work)
        ->and($state->effectiveUrl())->toBe('/');

    $trailingSlashRequest = Request::create('https://example.com/path/');
    $trailingSlashWork = new FrontendWork($trailingSlashRequest, new FrontendState);
    $step->handle($trailingSlashWork, fn (FrontendWork $frontendWork): FrontendWork => $frontendWork);

    expect($trailingSlashWork->state->effectiveUrl())->toBe('/path/');
});

it('preserves the parent path when stripping a trailing index.php', function (): void {
    $state = new FrontendState;
    $request = Request::create('https://example.com/docs/index.php');
    $work = new FrontendWork($request, $state);

    resolve(ParseUrlStep::class)->handle(
        $work,
        fn (FrontendWork $frontendWork): FrontendWork => $frontendWork,
    );

    expect($state->effectiveUrl())->toBe('/docs/');
});
