<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;

final class QueueMarketplaceInstallAttemptAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $requestedOptions
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $deploymentMetadata
     */
    public function handle(
        ExtensionListingData $listing,
        ExtensionAcquisitionData $acquisition,
        MarketplaceInstallEligibilityData $eligibility,
        array $requestedOptions = [],
        array $context = [],
        array $deploymentMetadata = [],
        ?string $telemetryStatus = null,
        ?Authenticatable $user = null,
    ): MarketplaceInstallAttempt {
        $lock = Cache::lock('capell-marketplace:queue-install:' . hash('sha256', $acquisition->composerName), 10);

        if (! $lock->get()) {
            $this->throwDuplicateActiveInstall($acquisition->composerName);
        }

        try {
            return $this->queueWithLock(
                listing: $listing,
                acquisition: $acquisition,
                eligibility: $eligibility,
                requestedOptions: $requestedOptions,
                context: $context,
                deploymentMetadata: $deploymentMetadata,
                telemetryStatus: $telemetryStatus,
                user: $user,
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
        array $requestedOptions = [],
        array $context = [],
        array $deploymentMetadata = [],
        ?string $telemetryStatus = null,
        ?Authenticatable $user = null,
    ): MarketplaceInstallAttempt {
        $this->guardDuplicateActiveInstall($acquisition->composerName);

        $attempt = RecordMarketplaceInstallAttemptAction::run(
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            composerName: $acquisition->composerName,
            kind: $listing->kind,
            status: MarketplaceInstallIntentStatus::Queued,
            composerCommand: $acquisition->composerCommand,
            versionConstraint: $acquisition->versionConstraint,
            requestedOptions: $requestedOptions,
            eligibility: $eligibility->toArray(),
            context: $this->contextWithComposerAuth($context, $acquisition->composerAuth),
            deployment: $deploymentMetadata,
            failureReason: null,
            telemetryStatus: $telemetryStatus,
            user: $user,
        );

        $attempt->forceFill(['queued_at' => now()])->save();

        RecordMarketplaceInstallAttemptEventAction::run(
            attempt: $attempt,
            level: MarketplaceInstallAttemptEventLevel::Info,
            message: __('capell-marketplace::marketplace.operations.timeline_created'),
            stage: MarketplaceInstallFailureStage::Preflight,
        );

        $preflight = RunMarketplaceInstallPreflightChecksAction::run($attempt);

        if (! $preflight['passed']) {
            $firstFailure = collect($preflight['checks'])->first(fn (array $check): bool => $check['passed'] === false);
            $reason = is_array($firstFailure) ? (string) $firstFailure['message'] : (string) __('capell-marketplace::marketplace.operations.preflight_failed');
            $classification = ClassifyMarketplaceInstallFailureAction::run(
                stage: MarketplaceInstallFailureStage::Preflight,
                message: $reason,
            );

            $attempt->forceFill([
                'status' => MarketplaceInstallIntentStatus::Failed,
                'failure_reason' => $reason,
                'failure_type' => $classification['failure_type']->value,
                'failure_stage' => $classification['failure_stage']->value,
                'completed_at' => now(),
                'resolved_at' => null,
            ])->save();

            return $attempt;
        }

        $deployment = [
            ...PublishMarketplaceComposerChangeAction::run($acquisition, $listing),
            ...$deploymentMetadata,
        ];

        $attempt->forceFill(['deployment' => $deployment !== [] ? $deployment : null])->save();

        if (($deployment['status'] ?? null) === 'failed') {
            $classification = ClassifyMarketplaceInstallFailureAction::run(
                stage: MarketplaceInstallFailureStage::DeploymentHandoff,
                message: is_string($deployment['failure_reason'] ?? null) ? $deployment['failure_reason'] : null,
                deploymentStatus: 'failed',
            );

            $attempt->forceFill([
                'failure_type' => $classification['failure_type']->value,
                'failure_stage' => $classification['failure_stage']->value,
            ])->save();

            $reason = is_string($deployment['failure_reason'] ?? null)
                ? (string) __('capell-marketplace::marketplace.operations.deployment_failed_notification', [
                    'reason' => $deployment['failure_reason'],
                ])
                : (string) __('capell-marketplace::marketplace.operations.deployment_failed_notification', [
                    'reason' => __('capell-marketplace::marketplace.operations.deployment_unknown_failure'),
                ]);

            $attempt->forceFill(['failure_reason' => $reason])->save();
        }

        RunMarketplaceInstallAttemptJob::dispatchAfterResponse((int) $attempt->getKey());

        return $attempt;
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
            'composer_auth_encrypted' => Crypt::encryptString(json_encode($composerAuth, JSON_THROW_ON_ERROR)),
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
}
