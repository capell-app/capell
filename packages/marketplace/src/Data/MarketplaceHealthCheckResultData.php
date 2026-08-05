<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;

final readonly class MarketplaceHealthCheckResultData
{
    /**
     * @param  string|null  $skipReason  Why the boot probe could not run at all,
     *                                   when it could not. Distinct from failureReason: a skip means
     *                                   the operation is unverified, not that it is bad, and the two
     *                                   must never be collapsed into one another.
     */
    public function __construct(
        public MarketplaceHealthProbeOutcome $bootProbe,
        public MarketplaceHealthProbeOutcome $httpProbe,
        public ?string $failureReason = null,
        public string $bootProbeOutput = '',
        public ?string $skipReason = null,
    ) {}

    public function passed(): bool
    {
        return $this->bootProbe !== MarketplaceHealthProbeOutcome::Failed
            && $this->httpProbe !== MarketplaceHealthProbeOutcome::Failed;
    }

    /**
     * The check did not condemn the operation, but it did not confirm it either.
     *
     * The caller must not treat this as a pass on the timeline: an operator
     * looking at a finished install needs to be able to tell "a fresh process
     * booted this site" from "nothing ever looked".
     */
    public function unverified(): bool
    {
        return $this->bootProbe === MarketplaceHealthProbeOutcome::Skipped;
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
