<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Closure;

final readonly class MarketplaceSelectionBlockedDependencyData
{
    public function __construct(
        public string $name,
        public string $composerName,
        public string $failureReasonCode,
    ) {}

    /**
     * @param  Closure(string): string  $failureReasonLabel
     * @return array{name: string, composer_name: string, reason: string}
     */
    public function toComputedArray(
        string $unknownExtensionLabel,
        Closure $failureReasonLabel,
    ): array {
        return [
            'name' => $this->name !== '' ? $this->name : $unknownExtensionLabel,
            'composer_name' => $this->composerName,
            'reason' => $failureReasonLabel($this->failureReasonCode),
        ];
    }
}
