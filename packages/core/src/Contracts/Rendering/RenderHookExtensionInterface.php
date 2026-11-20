<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Rendering;

use Capell\Core\Data\Rendering\RenderHookContext;

/**
 * Renders an object-backed public hook contribution.
 *
 * Implementations can live in runtime packages without requiring Frontend.
 * The rendering adapter owns registration and placement. The returned string
 * is public HTML: prepare data before rendering and exclude authoring state.
 */
interface RenderHookExtensionInterface
{
    /** Return the complete public HTML fragment for the supplied context. */
    public function render(RenderHookContext $context): string;
}
