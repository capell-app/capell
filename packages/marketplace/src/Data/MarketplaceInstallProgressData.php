<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Spatie\LaravelData\Data;

final class MarketplaceInstallProgressData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $composerName,
        public readonly MarketplaceInstallIntentStatus $status,
        public readonly ?string $stage,
        public readonly int $progressCurrent,
        public readonly int $progressTotal,
        public readonly ?string $failureReason,
    ) {}

    public function isActive(): bool
    {
        return $this->status->isActiveInstallOperation();
    }

    public function succeeded(): bool
    {
        return $this->status === MarketplaceInstallIntentStatus::Succeeded;
    }
}
