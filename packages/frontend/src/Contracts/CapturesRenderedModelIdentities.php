<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\RenderedModelIdentitiesData;

/**
 * A rendered-model tracker that can report the models a render declared and
 * declare them again later, so a render served from cached data still names
 * every model it shows without loading them.
 */
interface CapturesRenderedModelIdentities extends RenderedModelTracker
{
    /** Start collecting every model identity tracked until the matching stop. Captures nest. */
    public function startCapturingIdentities(): void;

    /** Stop the innermost capture and return the identities it collected. */
    public function stopCapturingIdentities(): RenderedModelIdentitiesData;

    /** Declare identities an earlier render captured, as though their models were loaded again. */
    public function trackIdentities(RenderedModelIdentitiesData $identities): void;
}
