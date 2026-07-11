<?php

declare(strict_types=1);

use Capell\Core\Models\Theme;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\ThemeResolverStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;

it('resolves theme from page then site then default', function (): void {
    Theme::factory()->default()->create();

    $state = new FrontendState;
    $work = new FrontendWork(Request::create('/'), $state);

    $step = resolve(ThemeResolverStep::class);
    $next = fn (FrontendWork $w): FrontendWork => $w;
    $response = $step->handle($work, $next);

    expect($response->state->theme()->key)
        ->toBe(config('capell-frontend.foundation_theme', 'default'));
});
