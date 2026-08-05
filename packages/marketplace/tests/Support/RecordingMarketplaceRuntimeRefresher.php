<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Marketplace\Contracts\MarketplaceRuntimeRefresher;

/**
 * A runtime refresher that succeeds and counts how many times it was asked.
 *
 * Named rather than anonymous so tests can state what they observe: whether the
 * action refreshed this node at all is the behaviour under test.
 */
final class RecordingMarketplaceRuntimeRefresher implements MarketplaceRuntimeRefresher
{
    public int $refreshCount = 0;

    public function refresh(): bool
    {
        $this->refreshCount++;

        return true;
    }
}
