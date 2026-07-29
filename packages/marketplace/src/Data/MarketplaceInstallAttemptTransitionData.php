<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Spatie\LaravelData\Data;

final class MarketplaceInstallAttemptTransitionData extends Data
{
    /** @param array<string, mixed> $timelineContext */
    public function __construct(
        public readonly MarketplaceInstallIntentStatus $toStatus,
        public readonly ?string $failureReason = null,
        public readonly ?MarketplaceInstallFailureStage $failureStage = null,
        public readonly ?MarketplaceComposerResultData $composerResult = null,
        public readonly ?string $outputExcerpt = null,
        public readonly ?string $errorExcerpt = null,
        public readonly ?string $timelineMessage = null,
        public readonly ?MarketplaceInstallAttemptEventLevel $timelineLevel = null,
        public readonly ?MarketplaceInstallFailureStage $timelineStage = null,
        public readonly array $timelineContext = [],
        public readonly ?string $timelineOutputExcerpt = null,
        public readonly ?int $attemptCount = null,
        public readonly ?int $progressTotal = null,
    ) {}
}
