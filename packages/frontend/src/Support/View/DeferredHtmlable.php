<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\View;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Stringable;

/**
 * Wraps a closure as an Htmlable so it is only evaluated when echoed (via e() or {!! !!}).
 *
 * This solves the Blade slot evaluation-order problem: Blade captures default slot content
 * synchronously before the wrapping component's template runs. By passing a DeferredHtmlable
 * as an explicit prop instead of slot content, rendering is deferred until the slot widget
 * calls {{ $pageSlot }}, which happens after all layout containers (and their widgets) have run.
 */
final class DeferredHtmlable implements Htmlable, Stringable
{
    /** @param Closure(): string $renderer */
    public function __construct(private readonly Closure $renderer) {}

    public function __toString(): string
    {
        return $this->toHtml();
    }

    public function toHtml(): string
    {
        return ($this->renderer)();
    }
}
