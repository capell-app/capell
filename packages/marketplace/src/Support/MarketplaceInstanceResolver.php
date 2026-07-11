<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Marketplace\Models\MarketplaceInstance;
use Throwable;

final class MarketplaceInstanceResolver
{
    private bool $latestInstanceResolved = false;

    private ?MarketplaceInstance $latestInstance = null;

    public function latest(): ?MarketplaceInstance
    {
        if (! $this->latestInstanceResolved) {
            $this->latestInstance = $this->resolveLatest();
            $this->latestInstanceResolved = true;
        }

        return $this->latestInstance;
    }

    public function forget(): void
    {
        $this->latestInstanceResolved = false;
        $this->latestInstance = null;
    }

    private function resolveLatest(): ?MarketplaceInstance
    {
        try {
            return MarketplaceInstance::query()
                ->latest('last_heartbeat_at')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }
}
