<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Spatie\LaravelData\Data;

final class MarketplaceCatalogueLocalStateData extends Data
{
    public function __construct(
        public readonly bool $isInstalled,
        public readonly ?string $installedVersion,
        public readonly bool $hasUpdateAvailable,
        public readonly ?int $activeOperationId,
        public readonly ?MarketplaceInstallIntentStatus $activeOperationStatus,
    ) {}

    public static function withoutLocalState(): self
    {
        return new self(
            isInstalled: false,
            installedVersion: null,
            hasUpdateAvailable: false,
            activeOperationId: null,
            activeOperationStatus: null,
        );
    }

    public function installInProgress(): bool
    {
        return $this->activeOperationId !== null;
    }
}
