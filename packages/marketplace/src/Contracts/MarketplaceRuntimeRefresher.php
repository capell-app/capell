<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

/**
 * Refreshes the caches and manifests a long-lived runtime is still holding from
 * before an install. Exists as a seam so the install job never reaches for the
 * console kernel directly, and so a host that refreshes some other way can
 * substitute its own.
 */
interface MarketplaceRuntimeRefresher
{
    /** @return bool Whether the refresh completed successfully. */
    public function refresh(): bool;
}
