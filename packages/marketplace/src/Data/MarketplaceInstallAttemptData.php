<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

final class MarketplaceInstallAttemptData extends Data
{
    /**
     * @param  array<string, mixed>  $requestedOptions
     * @param  array<string, mixed>  $eligibility
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $deployment
     * @param  array<string, mixed>  $timelineContext
     * @param  array<string, mixed>  $uninstallOptions
     */
    public function __construct(
        public readonly string $extensionSlug,
        public readonly string $extensionName,
        public readonly string $composerName,
        public readonly string $kind,
        public readonly MarketplaceInstallIntentStatus $status,
        public readonly bool $betaAcknowledged,
        public readonly MarketplaceOperationType $operation = MarketplaceOperationType::Install,
        public readonly array $uninstallOptions = [],
        public readonly ?MarketplaceInstallPolicyEvidenceData $policyEvidence = null,
        public readonly ?MarketplaceInstallActorData $actor = null,
        public readonly ?MarketplaceInstallSource $source = null,
        public readonly ?string $composerCommand = null,
        public readonly ?string $versionConstraint = null,
        public readonly array $requestedOptions = [],
        public readonly array $eligibility = [],
        public readonly array $context = [],
        public readonly array $deployment = [],
        public readonly ?string $failureReason = null,
        public readonly ?string $telemetryStatus = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $retryOfId = null,
        public readonly ?string $retriedById = null,
        public readonly ?CarbonInterface $retriedAt = null,
        public readonly ?string $userId = null,
        public readonly ?string $userEmail = null,
        public readonly ?string $timelineMessage = null,
        public readonly ?MarketplaceInstallAttemptEventLevel $timelineLevel = null,
        public readonly ?MarketplaceInstallFailureStage $timelineStage = null,
        public readonly array $timelineContext = [],
        public readonly ?string $timelineOutputExcerpt = null,
        public readonly bool $initializeLifecycle = true,
    ) {}
}
