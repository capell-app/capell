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
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $lock = Cache::lock(
            'capell-marketplace:queue-install:' . hash('sha256', $attempt->composer_name),
            10,
        );

        if (! $lock->get()) {
            $this->throwDuplicateActiveInstall($attempt->composer_name);
        }

        try {
            $retry = $this->createRetryWithLock($attempt, $user);

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

            // Routed by the attempt's own operation. A retry repeats what was
            // asked for; it does not get to decide that a failed uninstall was
            // really an install.
            return DispatchMarketplaceOperationAttemptAction::run(
                attempt: $retry,
                queueConnection: (string) config('capell-marketplace.marketplace.operations_queue_connection', 'database'),
                queue: (string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'),
            );
        } finally {
            $lock->release();
        }
    }

    public function canRetry(MarketplaceInstallAttempt $attempt): bool
    {
        // Its Composer change went to the site's deploy pipeline but never
        // arrived. Retrying it here would run Composer on this site instead, so
        // the operator starts the operation again and it is handed over again.
        // Once the deploy did arrive, a retry is safe: it carries the delivered
        // marker on, so the job finishes setup without running Composer.
        if ($this->awaitedDeployment($attempt) && ! $this->deliveredByDeployment($attempt)) {
            return false;
        }

        if (in_array($attempt->status, [
            MarketplaceInstallIntentStatus::Failed,
            MarketplaceInstallIntentStatus::TimedOut,
        ], true)) {
            return true;
        }

        return $attempt->status === MarketplaceInstallIntentStatus::Cancelled
            && $attempt->failure_type === MarketplaceInstallFailureType::CancelledAfterComposer->value;
    }

    private function awaitedDeployment(MarketplaceInstallAttempt $attempt): bool
    {
        return ($attempt->context[ResumeMarketplaceAttemptsAwaitingDeploymentAction::AWAITED_CONTEXT_KEY] ?? false) === true;
    }

    private function deliveredByDeployment(MarketplaceInstallAttempt $attempt): bool
    {
        return ($attempt->context[ResumeMarketplaceAttemptsAwaitingDeploymentAction::DELIVERED_CONTEXT_KEY] ?? false) === true;
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

    private function createRetryWithLock(
        MarketplaceInstallAttempt $attempt,
        ?Authenticatable $user,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $user): MarketplaceInstallAttempt {
            $source = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canRetry($source)) {
                throw ValidationException::withMessages([
                    'attempt' => __('capell-marketplace::marketplace.operations.retry_unavailable'),
                ]);
            }

            $activeAttempt = MarketplaceInstallAttempt::query()
                ->where('composer_name', $source->composer_name)
                ->whereIn('status', MarketplaceInstallIntentStatus::activeOperationValues())
                ->lockForUpdate()
                ->first();

            if ($activeAttempt instanceof MarketplaceInstallAttempt) {
                $this->throwDuplicateActiveInstall($source->composer_name);
            }

            $retry = CreateMarketplaceInstallAttemptAction::run(new MarketplaceInstallAttemptData(
                extensionSlug: $source->extension_slug,
                extensionName: $source->extension_name,
                composerName: $source->composer_name,
                kind: $source->kind,
                status: MarketplaceInstallIntentStatus::Queued,
                betaAcknowledged: (bool) $source->beta_acknowledged,
                // Both carried, because a retry that dropped either would
                // silently become a different operation: an install of the
                // package the operator was removing, or an uninstall that keeps
                // files they asked to delete.
                operation: $source->operation,
                uninstallOptions: $source->uninstall_options ?? [],
                policyEvidence: $this->policyEvidence($source),
                composerCommand: $source->composer_command,
                versionConstraint: $source->version_constraint,
                requestedOptions: $source->requested_options ?? [],
                eligibility: $source->eligibility ?? [],
                context: $source->context ?? [],
                deployment: $source->deployment ?? [],
                idempotencyKey: Str::uuid()->toString(),
                retryOfId: (int) $source->getKey(),
                retriedById: $this->userId($user),
                retriedAt: now(),
                userId: is_scalar($source->user_id) ? (string) $source->user_id : null,
                userEmail: $source->user_email,
                timelineMessage: (string) __('capell-marketplace::marketplace.operations.timeline_retry_created'),
                timelineLevel: MarketplaceInstallAttemptEventLevel::Info,
                timelineStage: MarketplaceInstallFailureStage::Preflight,
                timelineContext: ['retry_of_id' => $source->getKey()],
            ));

            // Create strips the deployment markers from every new attempt, so
            // a retry of a delivered attempt sets them again here, from the
            // locked source row rather than from anything a caller supplied.
            if ($this->awaitedDeployment($source) && $this->deliveredByDeployment($source)) {
                $retry->forceFill(['context' => [
                    ...($retry->context ?? []),
                    ResumeMarketplaceAttemptsAwaitingDeploymentAction::AWAITED_CONTEXT_KEY => true,
                    ResumeMarketplaceAttemptsAwaitingDeploymentAction::DELIVERED_CONTEXT_KEY => true,
                ]])->save();
            }

            return $retry;
        });
    }

    private function throwDuplicateActiveInstall(string $composerName): never
    {
        throw ValidationException::withMessages([
            'composer_name' => __('capell-marketplace::marketplace.operations.duplicate_active', [
                'package' => $composerName,
            ]),
        ]);
    }
}
