<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\AssertQueueConnectionReadyAction;
use Capell\Core\Exceptions\QueueConnectionNotReadyException;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceComposerAuthContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Put an update on the queue, with the same guarantees an install gets.
 *
 * Deliberately the same shape as QueueMarketplaceInstallAttemptAction — same
 * cross-process lock, same duplicate guard, same queue-readiness assertion, same
 * preflight — because the thing being protected is the release root, and it does
 * not care which operation is about to rewrite it.
 */
final class QueueMarketplaceUpdateAttemptAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(
        ExtensionListingData $listing,
        ExtensionAcquisitionData $acquisition,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source,
        string $currentVersion,
        array $context = [],
        ?Authenticatable $user = null,
        ?string $idempotencyKey = null,
    ): MarketplaceInstallAttempt {
        $existingAttempt = $this->findIdempotentAttempt($idempotencyKey);

        if ($existingAttempt instanceof MarketplaceInstallAttempt) {
            return $existingAttempt;
        }

        // The database guard below cannot see an attempt another process has
        // decided to create but not yet written, which is exactly the race a
        // bulk update opens by queueing several packages in a tight loop.
        $lock = Cache::lock('capell-marketplace:queue-install:' . hash('sha256', $acquisition->composerName), 10);

        if (! $lock->get()) {
            AssertNoActiveMarketplaceOperationAction::fail($acquisition->composerName);
        }

        try {
            return $this->queueWithLock(
                listing: $listing,
                acquisition: $acquisition,
                actor: $actor,
                source: $source,
                currentVersion: $currentVersion,
                context: $context,
                user: $user,
                idempotencyKey: $idempotencyKey,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function queueWithLock(
        ExtensionListingData $listing,
        ExtensionAcquisitionData $acquisition,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source,
        string $currentVersion,
        array $context,
        ?Authenticatable $user,
        ?string $idempotencyKey,
    ): MarketplaceInstallAttempt {
        $existingAttempt = $this->findIdempotentAttempt($idempotencyKey);

        if ($existingAttempt instanceof MarketplaceInstallAttempt) {
            return $existingAttempt;
        }

        $queueConnection = (string) config('capell-marketplace.marketplace.operations_queue_connection', 'database');

        try {
            AssertQueueConnectionReadyAction::run($queueConnection);
        } catch (QueueConnectionNotReadyException $queueConnectionNotReadyException) {
            throw ValidationException::withMessages([
                'queue_connection' => $queueConnectionNotReadyException->getMessage(),
            ]);
        }

        AssertNoActiveMarketplaceOperationAction::run($acquisition->composerName);

        $attempt = CreateMarketplaceInstallAttemptAction::run(new MarketplaceInstallAttemptData(
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            composerName: $acquisition->composerName,
            kind: $listing->kind,
            status: MarketplaceInstallIntentStatus::Queued,
            betaAcknowledged: false,
            operation: MarketplaceOperationType::Update,
            actor: $actor,
            source: $source,
            composerCommand: $acquisition->composerCommand,
            versionConstraint: $acquisition->versionConstraint,
            eligibility: $acquisition->authorizationEligibilityPolicy?->toArray() ?? [],
            context: MarketplaceComposerAuthContext::encryptedInto([
                ...$context,
                'update_from_version' => $currentVersion,
            ], $acquisition->composerAuth),
            idempotencyKey: $idempotencyKey,
            timelineMessage: (string) __('capell-marketplace::marketplace.operations.timeline_created'),
            timelineLevel: MarketplaceInstallAttemptEventLevel::Info,
            timelineStage: MarketplaceInstallFailureStage::Preflight,
        ), $user);

        $preflight = RunMarketplaceInstallPreflightChecksAction::run($attempt);

        if (! $preflight['passed']) {
            $firstFailure = collect($preflight['checks'])->first(fn (array $check): bool => $check['passed'] === false);
            $reason = is_array($firstFailure)
                ? (string) $firstFailure['message']
                : (string) __('capell-marketplace::marketplace.operations.preflight_failed');

            return TransitionMarketplaceInstallAttemptAction::run(
                $attempt,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Failed,
                    failureReason: $reason,
                    failureStage: MarketplaceInstallFailureStage::Preflight,
                ),
            );
        }

        return DispatchMarketplaceUpdateAttemptAction::run(
            attempt: $attempt,
            queueConnection: $queueConnection,
            queue: (string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'),
        );
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
