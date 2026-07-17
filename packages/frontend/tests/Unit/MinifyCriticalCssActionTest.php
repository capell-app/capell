<?php

declare(strict_types=1);

use Capell\Frontend\Actions\MinifyCriticalCssAction;

function minifyCriticalCss(string $css): string
{
    return MinifyCriticalCssAction::run($css);
}

it('preserves the descendant combinator before a pseudo-class selector', function (): void {
    // Regression: the old minifier stripped whitespace around ':' which merged a
    // descendant combinator (`.logo-glow :is(svg, img)`) into a compound selector
    // (`.logo-glow:is(svg, img)`) that matches nothing -- collapsing the header
    // logo to its 16x16 fallback under critical-only rendering.
    $minified = minifyCriticalCss('.logo-glow :is(svg, img) { height: var(--h, 0.625rem); }');

    expect($minified)
        ->toContain('.logo-glow :is(svg,img)')
        ->not->toContain('.logo-glow:is(svg,img)');
});

it('preserves descendant combinators before :not(), :hover and :where()', function (): void {
    $minified = minifyCriticalCss(
        "html:not([data-frontend-styles='loaded']) :is(.a, .b){display:none}\n" .
        '[data-site-header] a :hover{color:red}' .
        '[data-x] :where(.c){color:blue}',
    );

    expect($minified)
        ->toContain("loaded']) :is(.a,.b)")
        ->toContain('a :hover')
        ->toContain('[data-x] :where(.c)');
});

it('keeps compound pseudo-class selectors (no leading space) compact', function (): void {
    expect(minifyCriticalCss('a:hover{color:red}'))->toContain('a:hover{');
    expect(minifyCriticalCss('html:not([x]) .y{color:red}'))->toContain('html:not([x]) .y');
});

it('minifies declarations by stripping space after the colon', function (): void {
    expect(minifyCriticalCss('.x { color: red; height: 1rem; }'))
        ->toBe('.x{color:red;height:1rem}');
});

it('keeps the space media features require', function (): void {
    expect(minifyCriticalCss('@media (min-width: 120rem){.x{gap:1rem}}'))
        ->toBe('@media (min-width:120rem){.x{gap:1rem}}');
});

it('strips comments, collapses !important and trailing semicolons', function (): void {
    $minified = minifyCriticalCss(
        "/* a comment */\n.x {\n    color: red !important;\n    gap: 1rem;\n}\n",
    );

    expect($minified)
        ->not->toContain('/*')
        ->toContain('color:red!important')
        ->toBe('.x{color:red!important;gap:1rem}');
});

it('returns an empty string for empty input', function (): void {
    expect(minifyCriticalCss(''))->toBe('');
});
