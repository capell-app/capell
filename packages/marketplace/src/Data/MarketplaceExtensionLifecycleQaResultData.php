<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceExtensionLifecycleQaResultData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $composerName,
        public readonly string $installResult,
        public readonly string $uninstallResult,
        public readonly string $deleteResult,
        public readonly ?string $failureReason = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toReportArray(): array
    {
        return [
            'extension' => $this->name,
            'composer_package' => $this->composerName,
            'install' => $this->installResult,
            'uninstall' => $this->uninstallResult,
            'delete' => $this->deleteResult,
            'failure_reason' => $this->failureReason,
        ];
    }

    public function failed(): bool
    {
        return $this->failureReason !== null
            || $this->installResult === 'failed'
            || $this->uninstallResult === 'failed'
            || $this->deleteResult === 'failed';
    }
}
