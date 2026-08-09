<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceReadinessStatus;
use Spatie\LaravelData\Data;

final class MarketplaceReadinessCheckData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly MarketplaceReadinessStatus $status,
        public readonly string $message,
        public readonly ?string $remediation = null,
        public readonly ?string $docsAnchor = null,
        /**
         * True when a failure describes a deliberate hosting shape (a shared host,
         * an immutable release root) rather than something an operator broke.
         * By-design failures downgrade the host to a manual or deploy-published
         * install; anything else blocks it.
         */
        public readonly bool $byDesign = false,
    ) {}

    public function failed(): bool
    {
        return $this->status === MarketplaceReadinessStatus::Fail;
    }

    public function warned(): bool
    {
        return $this->status === MarketplaceReadinessStatus::Warn;
    }

    public function passed(): bool
    {
        return $this->status === MarketplaceReadinessStatus::Pass;
    }
}
