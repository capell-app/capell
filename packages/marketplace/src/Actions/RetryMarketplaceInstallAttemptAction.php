<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

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

        $retry = MarketplaceInstallAttempt::query()->create([
            'composer_name' => $attempt->composer_name,
            'extension_slug' => $attempt->extension_slug,
            'extension_name' => $attempt->extension_name,
            'kind' => $attempt->kind,
            'status' => MarketplaceInstallIntentStatus::Queued,
            'composer_command' => $attempt->composer_command,
            'version_constraint' => $attempt->version_constraint,
            'requested_options' => $attempt->requested_options,
            'eligibility' => $attempt->eligibility,
            'context' => $attempt->context,
            'deployment' => $attempt->deployment,
            'retry_of_id' => $attempt->getKey(),
            'retried_by_id' => $this->userId($user),
            'retried_at' => now(),
            'queued_at' => now(),
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'user_id' => $attempt->user_id,
            'user_email' => $attempt->user_email,
        ]);

        RecordMarketplaceInstallAttemptEventAction::run(
            attempt: $retry,
            level: MarketplaceInstallAttemptEventLevel::Info,
            message: __('capell-marketplace::marketplace.operations.timeline_retry_created'),
            stage: MarketplaceInstallFailureStage::Preflight,
            context: ['retry_of_id' => $attempt->getKey()],
        );

        $preflight = RunMarketplaceInstallPreflightChecksAction::run($retry);

        if (! $preflight['passed']) {
            $firstFailure = collect($preflight['checks'])->first(fn (array $check): bool => $check['passed'] === false);
            $reason = is_array($firstFailure) ? (string) $firstFailure['message'] : (string) __('capell-marketplace::marketplace.operations.preflight_failed');
            $classification = ClassifyMarketplaceInstallFailureAction::run(
                stage: MarketplaceInstallFailureStage::Preflight,
                message: $reason,
            );

            $retry->forceFill([
                'status' => MarketplaceInstallIntentStatus::Failed,
                'failure_reason' => $reason,
                'failure_type' => $classification['failure_type']->value,
                'failure_stage' => $classification['failure_stage']->value,
                'completed_at' => now(),
            ])->save();

            return $retry;
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
}
