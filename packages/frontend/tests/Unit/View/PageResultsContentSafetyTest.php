<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('sanitizes no results content before rendering public results output', function (): void {
    $html = Blade::render(
        '<x-capell::page.results :results="$results" :no-results-text="$noResultsText" />',
        [
            'results' => collect(),
            'noResultsText' => '<strong>Nothing found</strong><script>alert(1)</script><span onclick="alert(2)">Try again</span>',
        ],
    );

    expect($html)
        ->toContain('<strong>Nothing found</strong>')
        ->toContain('<span>Try again</span>')
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('onclick=');
});
