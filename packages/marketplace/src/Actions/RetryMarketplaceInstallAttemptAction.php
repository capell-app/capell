<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallAttemptData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RetryMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt, ?Authenticatable $user = null): MarketplaceInstallAttempt
    {
        if (! $this->canRetry($attempt)) {
            throw ValidationException::withMessages([
                'attempt' => __('capell-marketplace::marketplace.operations.retry_unavailable'),
            ]);
        }

        $retry = CreateMarketplaceInstallAttemptAction::run(new MarketplaceInstallAttemptData(
            extensionSlug: $attempt->extension_slug,
            extensionName: $attempt->extension_name,
            composerName: $attempt->composer_name,
            kind: $attempt->kind,
            status: MarketplaceInstallIntentStatus::Queued,
            betaAcknowledged: (bool) $attempt->beta_acknowledged,
            policyEvidence: $this->policyEvidence($attempt),
            composerCommand: $attempt->composer_command,
            versionConstraint: $attempt->version_constraint,
            requestedOptions: $attempt->requested_options ?? [],
            eligibility: $attempt->eligibility ?? [],
            context: $attempt->context ?? [],
            deployment: $attempt->deployment ?? [],
            idempotencyKey: Str::uuid()->toString(),
            retryOfId: (int) $attempt->getKey(),
            retriedById: $this->userId($user),
            retriedAt: now(),
            userId: is_scalar($attempt->user_id) ? (string) $attempt->user_id : null,
            userEmail: $attempt->user_email,
            timelineMessage: (string) __('capell-marketplace::marketplace.operations.timeline_retry_created'),
            timelineLevel: MarketplaceInstallAttemptEventLevel::Info,
            timelineStage: MarketplaceInstallFailureStage::Preflight,
            timelineContext: ['retry_of_id' => $attempt->getKey()],
        ));

        $preflight = RunMarketplaceInstallPreflightChecksAction::run($retry);

        if (! $preflight['passed']) {
            $firstFailure = collect($preflight['checks'])->first(fn (array $check): bool => $check['passed'] === false);
            $reason = is_array($firstFailure) ? (string) $firstFailure['message'] : (string) __('capell-marketplace::marketplace.operations.preflight_failed');

            return TransitionMarketplaceInstallAttemptAction::run(
                $retry,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Failed,
                    failureReason: $reason,
                    failureStage: MarketplaceInstallFailureStage::Preflight,
                ),
            );
        }

        dispatch(new RunMarketplaceInstallAttemptJob((int) $retry->getKey()))
            ->onConnection((string) config('capell-marketplace.marketplace.operations_queue_connection', 'database'))
            ->onQueue((string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'));

        return $retry;
    }

    public function canRetry(MarketplaceInstallAttempt $attempt): bool
    {
        if (in_array($attempt->status, [
            MarketplaceInstallIntentStatus::Failed,
            MarketplaceInstallIntentStatus::TimedOut,
        ], true)) {
            return true;
        }

        return $attempt->status === MarketplaceInstallIntentStatus::Cancelled
            && $attempt->failure_type === MarketplaceInstallFailureType::CancelledAfterComposer->value;
    }

    private function userId(?Authenticatable $user): ?string
    {
        $identifier = $user?->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : null;
    }

    private function policyEvidence(
        MarketplaceInstallAttempt $attempt,
    ): ?MarketplaceInstallPolicyEvidenceData {
        if (! is_array($attempt->policy_evidence)) {
            return null;
        }

        return MarketplaceInstallPolicyEvidenceData::from($attempt->policy_evidence);
    }
}
