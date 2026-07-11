<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\LayoutResolverStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;

it('resolves layout from page then site then default', function (): void {
    Layout::factory()->default()->create();

    $state = new FrontendState;
    $work = new FrontendWork(Request::create('/'), $state);

    // No page/site -> default
    $step = resolve(LayoutResolverStep::class);
    $next = fn (FrontendWork $w): FrontendWork => $w;
    $response = $step->handle($work, $next);

    expect($response->state->layout()->key)
        ->toBe(config('capell-frontend.default_layout', 'default'));
});
