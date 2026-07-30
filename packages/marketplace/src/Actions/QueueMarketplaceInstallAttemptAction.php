<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\AssertQueueConnectionReadyAction;
use Capell\Core\Exceptions\QueueConnectionNotReadyException;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Data\MarketplaceInstallDeploymentData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class QueueMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $requestedOptions
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $deploymentMetadata
     */
    public function handle(
        ExtensionListingData $listing,
        ExtensionAcquisitionData $acquisition,
        MarketplaceInstallEligibilityData $eligibility,
        bool $betaAcknowledged,
        MarketplaceInstallPolicyEvidenceData $policyEvidence,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source,
        array $requestedOptions = [],
        array $context = [],
        array $deploymentMetadata = [],
        ?string $telemetryStatus = null,
        ?Authenticatable $user = null,
        bool $afterResponse = true,
        ?string $idempotencyKey = null,
    ): MarketplaceInstallAttempt {
        $existingAttempt = $this->findIdempotentAttempt($idempotencyKey);

        if ($existingAttempt instanceof MarketplaceInstallAttempt) {
            return $existingAttempt;
        }

        $lock = Cache::lock('capell-marketplace:queue-install:' . hash('sha256', $acquisition->composerName), 10);

        if (! $lock->get()) {
            $this->throwDuplicateActiveInstall($acquisition->composerName);
        }

        try {
            return $this->queueWithLock(
                listing: $listing,
                acquisition: $acquisition,
                eligibility: $eligibility,
                betaAcknowledged: $betaAcknowledged,
                policyEvidence: $policyEvidence,
                actor: $actor,
                source: $source,
                requestedOptions: $requestedOptions,
                context: $context,
                deploymentMetadata: $deploymentMetadata,
                telemetryStatus: $telemetryStatus,
                user: $user,
                afterResponse: $afterResponse,
                idempotencyKey: $idempotencyKey,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $requestedOptions
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $deploymentMetadata
     */
    private function queueWithLock(
        ExtensionListingData $listing,
        ExtensionAcquisitionData $acquisition,
        MarketplaceInstallEligibilityData $eligibility,
        bool $betaAcknowledged,
        MarketplaceInstallPolicyEvidenceData $policyEvidence,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source,
        array $requestedOptions = [],
        array $context = [],
        array $deploymentMetadata = [],
        ?string $telemetryStatus = null,
        ?Authenticatable $user = null,
        bool $afterResponse = true,
        ?string $idempotencyKey = null,
    ): MarketplaceInstallAttempt {
        $existingAttempt = $this->findIdempotentAttempt($idempotencyKey);

        if ($existingAttempt instanceof MarketplaceInstallAttempt) {
            return $existingAttempt;
        }

        if (! $policyEvidence->entitlementAllowed
            || ! $policyEvidence->compatibilityAllowed
            || ! $policyEvidence->consentAllowed) {
            return $this->recordPolicyBlockedAttempt(
                listing: $listing,
                acquisition: $acquisition,
                eligibility: $eligibility,
                betaAcknowledged: $betaAcknowledged,
                policyEvidence: $policyEvidence,
                actor: $actor,
                source: $source,
                requestedOptions: $requestedOptions,
                context: $context,
                deploymentMetadata: $deploymentMetadata,
                telemetryStatus: $telemetryStatus,
                user: $user,
            );
        }

        $queueConnection = (string) config('capell-marketplace.marketplace.operations_queue_connection', 'database');

        try {
            AssertQueueConnectionReadyAction::run($queueConnection);
        } catch (QueueConnectionNotReadyException $queueConnectionNotReadyException) {
            throw ValidationException::withMessages([
                'queue_connection' => $queueConnectionNotReadyException->getMessage(),
            ]);
        }

        $this->guardDuplicateActiveInstall($acquisition->composerName);

        $attempt = CreateMarketplaceInstallAttemptAction::run(new MarketplaceInstallAttemptData(
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            composerName: $acquisition->composerName,
            kind: $listing->kind,
            status: MarketplaceInstallIntentStatus::Queued,
            betaAcknowledged: $betaAcknowledged,
            policyEvidence: $policyEvidence,
            actor: $actor,
            source: $source,
            composerCommand: $acquisition->composerCommand,
            versionConstraint: $acquisition->versionConstraint,
            requestedOptions: $requestedOptions,
            eligibility: $eligibility->toArray(),
            context: $this->contextWithComposerAuth($context, $acquisition->composerAuth),
            deployment: $deploymentMetadata,
            telemetryStatus: $telemetryStatus,
            idempotencyKey: $idempotencyKey,
            timelineMessage: (string) __('capell-marketplace::marketplace.operations.timeline_created'),
            timelineLevel: MarketplaceInstallAttemptEventLevel::Info,
            timelineStage: MarketplaceInstallFailureStage::Preflight,
        ), $user);

        $preflight = RunMarketplaceInstallPreflightChecksAction::run($attempt);

        if (! $preflight['passed']) {
            $firstFailure = collect($preflight['checks'])->first(fn (array $check): bool => $check['passed'] === false);
            $reason = is_array($firstFailure) ? (string) $firstFailure['message'] : (string) __('capell-marketplace::marketplace.operations.preflight_failed');

            return TransitionMarketplaceInstallAttemptAction::run(
                $attempt,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Failed,
                    failureReason: $reason,
                    failureStage: MarketplaceInstallFailureStage::Preflight,
                ),
            );
        }

        if (PackageIsAvailableForLifecycleAction::run($attempt->composer_name)) {
            $deployment = $deploymentMetadata;
        } else {
            $claimedAttempt = ClaimMarketplaceInstallDeploymentPublicationAction::run($attempt);

            if (! $claimedAttempt instanceof MarketplaceInstallAttempt) {
                return $attempt->refresh();
            }

            $attempt = $claimedAttempt;
            $deployment = [
                ...PublishMarketplaceComposerChangeAction::run($acquisition, $listing, $attempt),
                ...$deploymentMetadata,
            ];
        }

        $attempt = RecordMarketplaceInstallDeploymentAction::run(
            $attempt,
            new MarketplaceInstallDeploymentData($deployment),
        );

        return DispatchMarketplaceInstallAttemptAction::run(
            attempt: $attempt,
            queueConnection: $queueConnection,
            queue: (string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'),
        );
    }

    /**
     * @param  array<string, mixed>  $requestedOptions
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $deploymentMetadata
     */
    private function recordPolicyBlockedAttempt(
        ExtensionListingData $listing,
        ExtensionAcquisitionData $acquisition,
        MarketplaceInstallEligibilityData $eligibility,
        bool $betaAcknowledged,
        MarketplaceInstallPolicyEvidenceData $policyEvidence,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source,
        array $requestedOptions,
        array $context,
        array $deploymentMetadata,
        ?string $telemetryStatus,
        ?Authenticatable $user,
    ): MarketplaceInstallAttempt {
        $reason = $policyEvidence->reason
            ?? match (false) {
                $policyEvidence->compatibilityAllowed => 'incompatible',
                $policyEvidence->entitlementAllowed => 'entitlement_required',
                default => $policyEvidence->blockingDependency !== null
                    ? 'beta_dependency_acknowledgement_required'
                    : 'beta_acknowledgement_required',
            };

        return CreateMarketplaceInstallAttemptAction::run(new MarketplaceInstallAttemptData(
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            composerName: $acquisition->composerName,
            kind: $listing->kind,
            status: MarketplaceInstallIntentStatus::Blocked,
            betaAcknowledged: $betaAcknowledged,
            policyEvidence: $policyEvidence,
            actor: $actor,
            source: $source,
            composerCommand: $acquisition->composerCommand,
            versionConstraint: $acquisition->versionConstraint,
            requestedOptions: $requestedOptions,
            eligibility: $eligibility->toArray(),
            context: $context,
            deployment: $deploymentMetadata,
            failureReason: $reason,
            telemetryStatus: $telemetryStatus,
        ), $user);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $composerAuth
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function contextWithComposerAuth(array $context, ?array $composerAuth): array
    {
        if ($composerAuth === null || $composerAuth === []) {
            return $context;
        }

        return [
            ...$context,
            'composer_auth_encrypted' => Crypt::encryptString(JsonCodec::encode($composerAuth)),
        ];
    }

    private function guardDuplicateActiveInstall(string $composerName): void
    {
        $active = MarketplaceInstallAttempt::query()
            ->where('composer_name', $composerName)
            ->whereIn('status', array_map(
                static fn (MarketplaceInstallIntentStatus $status): string => $status->value,
                [
                    MarketplaceInstallIntentStatus::Queued,
                    MarketplaceInstallIntentStatus::Running,
                    MarketplaceInstallIntentStatus::CancelRequested,
                ],
            ))
            ->exists();

        if (! $active) {
            return;
        }

        $this->throwDuplicateActiveInstall($composerName);
    }

    private function throwDuplicateActiveInstall(string $composerName): never
    {
        throw ValidationException::withMessages([
            'composer_name' => __('capell-marketplace::marketplace.operations.duplicate_active', [
                'package' => $composerName,
            ]),
        ]);
    }

    private function findIdempotentAttempt(?string $idempotencyKey): ?MarketplaceInstallAttempt
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return null;
        }

        return MarketplaceInstallAttempt::query()
            ->where('idempotency_key', hash('sha256', $idempotencyKey))
            ->first();
    }
}
