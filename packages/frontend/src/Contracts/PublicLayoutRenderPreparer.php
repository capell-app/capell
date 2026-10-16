<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRenderContextData;

/**
 * Optional layout-provider bridge, resolved only for the layout rendering path.
 * Prepare request-scoped widget contexts (including fragment references and
 * versions) here. Layout views must consume them without further reads.
 * This runs on cache hits too; request-specific contexts must not be cached in
 * the public graph or added to the saved layout/widget data.
 */
interface PublicLayoutRenderPreparer
{
    public function prepare(FrontendRenderContextData $context): void;
}
