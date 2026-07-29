<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @deprecated Use CreateMarketplaceInstallAttemptAction with MarketplaceInstallAttemptData.
 */
final class RecordMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $requestedOptions
     * @param  array<string, mixed>  $eligibility
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $deployment
     */
    public function handle(
        string $extensionSlug,
        string $extensionName,
        string $composerName,
        string $kind,
        MarketplaceInstallIntentStatus $status,
        bool $betaAcknowledged,
        MarketplaceInstallPolicyEvidenceData $policyEvidence,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source,
        ?string $composerCommand = null,
        ?string $versionConstraint = null,
        array $requestedOptions = [],
        array $eligibility = [],
        array $context = [],
        array $deployment = [],
        ?string $failureReason = null,
        ?string $telemetryStatus = null,
        ?Authenticatable $user = null,
        ?string $idempotencyKey = null,
    ): MarketplaceInstallAttempt {
        return CreateMarketplaceInstallAttemptAction::run(
            new MarketplaceInstallAttemptData(
                extensionSlug: $extensionSlug,
                extensionName: $extensionName,
                composerName: $composerName,
                kind: $kind,
                status: $status,
                betaAcknowledged: $betaAcknowledged,
                policyEvidence: $policyEvidence,
                actor: $actor,
                source: $source,
                composerCommand: $composerCommand,
                versionConstraint: $versionConstraint,
                requestedOptions: $requestedOptions,
                eligibility: $eligibility,
                context: $context,
                deployment: $deployment,
                failureReason: $failureReason,
                telemetryStatus: $telemetryStatus,
                idempotencyKey: $idempotencyKey,
                initializeLifecycle: false,
            ),
            $user,
        );
    }
}
