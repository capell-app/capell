<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Minifies hand-authored critical CSS for inlining into the `<style data-critical-css>` block.
 *
 * Unlike the previous inline minifiers, this collapses whitespace AFTER a colon only.
 * Stripping whitespace BEFORE a colon merges a descendant combinator into a compound
 * selector (`.logo-glow :is(svg, img)` -> `.logo-glow:is(svg, img)`), which matches
 * nothing and silently drops above-fold rules from the critical CSS.
 */
final class MinifyCriticalCssAction
{
    use AsFake;
    use AsObject;

    public function handle(string $css): string
    {
        // Drop comments.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        // Collapse all runs of whitespace to a single space.
        $css = (string) preg_replace('/\s+/', ' ', $css);

        // Strip whitespace around structural delimiters. Note: ':' is deliberately
        // EXCLUDED -- a space before ':' is a descendant combinator before a pseudo
        // selector and must be preserved.
        $css = (string) preg_replace('/\s*([{};,>])\s*/', '$1', $css);

        // Strip whitespace AFTER a colon only (declarations + media features),
        // never before it.
        $css = (string) preg_replace('/:\s+/', ':', $css);

        // Tidy up.
        $css = str_replace(' !important', '!important', $css);
        $css = (string) preg_replace('/;\s*}/', '}', $css);

        return trim($css);
    }
}
