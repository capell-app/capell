<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRuntimeManifestData;

/**
 * Adds extension runtime requirements to the current frontend manifest.
 *
 * Bind implementations in the service container and tag them with TAG.
 * Contributors run after Capell creates the rendering-strategy defaults and
 * mutate the supplied request-local manifest in registration order.
 */
interface FrontendRuntimeManifestContributor
{
    public const string TAG = 'capell.frontend.runtime-manifest-contributor';

    /** Declare only runtime capabilities needed by the supplied context. */
    public function contribute(FrontendContextReader $context, FrontendRuntimeManifestData $manifest): void;
}
