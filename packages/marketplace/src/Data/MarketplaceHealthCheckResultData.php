<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;

final readonly class MarketplaceHealthCheckResultData
{
    public function __construct(
        public MarketplaceHealthProbeOutcome $bootProbe,
        public MarketplaceHealthProbeOutcome $httpProbe,
        public ?string $failureReason = null,
        public string $bootProbeOutput = '',
    ) {}

    public function passed(): bool
    {
        return $this->bootProbe !== MarketplaceHealthProbeOutcome::Failed
            && $this->httpProbe !== MarketplaceHealthProbeOutcome::Failed;
    }

    /**
     * @return array<string, string>
     */
    public function timelineContext(): array
    {
        return [
            'boot_probe' => $this->bootProbe->value,
            'http_probe' => $this->httpProbe->value,
        ];
    }
}
