<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\RenderHookContext;

/**
 * Renders an object-backed frontend hook contribution.
 *
 * Register an implementation through RenderHookRegistry or a manifest-backed
 * render-hook contribution. The returned string is public HTML and must obey
 * the public-output safety boundary; load required data before render().
 */
interface RenderHookExtensionInterface
{
    /** Return the complete HTML fragment for the supplied hook context. */
    public function render(RenderHookContext $context): string;
}
