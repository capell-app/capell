<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Data\MarketplaceInstallDeploymentData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Records what the deployment handoff said about an attempt, and moves the
 * attempt to match.
 *
 * A published handoff means the Composer change now travels through the
 * site's own deploy pipeline, where it can be reviewed. The attempt then waits
 * for that deploy instead of running Composer here: running it here would put
 * the code on the site before anyone merged it, and on a host whose release
 * roots are immutable it cannot run at all. A deployment provider that later
 * learns the deploy failed reports it through this action too, and the waiting
 * attempt fails with that reason.
 *
 * The lifecycle QA command is the one caller that opts out: it exists to run
 * the whole lifecycle on this site, inline, and an operator runs it on purpose.
 */
final class RecordMarketplaceInstallDeploymentAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallDeploymentData $data,
        bool $awaitPublishedDeployment = true,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $data, $awaitPublishedDeployment): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedAttempt->status, [
                MarketplaceInstallIntentStatus::Queued,
                MarketplaceInstallIntentStatus::AwaitingDeployment,
                MarketplaceInstallIntentStatus::Cancelled,
            ], true)) {
                throw new RuntimeException(sprintf(
                    'Cannot record deployment evidence for Marketplace install attempt in [%s] state.',
                    $lockedAttempt->status->value,
                ));
            }

            $status = $this->deploymentStatus($data);
            $attributes = [
                'deployment' => $data->deployment !== [] ? $data->deployment : null,
            ];

            if ($status === 'failed' && $lockedAttempt->status === MarketplaceInstallIntentStatus::AwaitingDeployment) {
                $lockedAttempt->forceFill($attributes)->save();
                $this->recordTimeline($lockedAttempt, $status, $data);

                return TransitionMarketplaceInstallAttemptAction::run(
                    $lockedAttempt,
                    new MarketplaceInstallAttemptTransitionData(
                        toStatus: MarketplaceInstallIntentStatus::Failed,
                        failureReason: (string) __('capell-marketplace::marketplace.operations.deployment_did_not_arrive', [
                            'reason' => $this->failureReason($data)
                                ?? __('capell-marketplace::marketplace.operations.deployment_unknown_failure'),
                        ]),
                        failureStage: MarketplaceInstallFailureStage::DeploymentHandoff,
                    ),
                );
            }

            if ($status === 'failed' && $lockedAttempt->status === MarketplaceInstallIntentStatus::Queued) {
                $reason = $this->failureReason($data);
                $classification = ClassifyMarketplaceInstallFailureAction::run(
                    stage: MarketplaceInstallFailureStage::DeploymentHandoff,
                    message: $reason,
                    deploymentStatus: $status,
                );

                $attributes = [
                    ...$attributes,
                    'failure_reason' => (string) __('capell-marketplace::marketplace.operations.deployment_failed_notification', [
                        'reason' => $reason
                            ?? __('capell-marketplace::marketplace.operations.deployment_unknown_failure'),
                    ]),
                    'failure_type' => $classification['failure_type']->value,
                    'failure_stage' => $classification['failure_stage']->value,
                ];
            }

            $awaitsDeployment = $awaitPublishedDeployment
                && $status === 'published'
                && $lockedAttempt->status === MarketplaceInstallIntentStatus::Queued;

            if ($awaitsDeployment) {
                $attributes = [
                    ...$attributes,
                    'status' => MarketplaceInstallIntentStatus::AwaitingDeployment,
                    'context' => [
                        ...($lockedAttempt->context ?? []),
                        ResumeMarketplaceAttemptsAwaitingDeploymentAction::AWAITED_CONTEXT_KEY => true,
                    ],
                    'current_stage' => MarketplaceInstallFailureStage::DeploymentHandoff->value,
                    'heartbeat_at' => now(),
                ];
            }

            $lockedAttempt->forceFill($attributes)->save();
            $this->recordTimeline($lockedAttempt, $status, $data);

            if ($awaitsDeployment) {
                RecordMarketplaceInstallAttemptEventAction::run(
                    attempt: $lockedAttempt,
                    level: MarketplaceInstallAttemptEventLevel::Info,
                    message: (string) __('capell-marketplace::marketplace.operations.timeline_deployment_awaiting'),
                    stage: MarketplaceInstallFailureStage::DeploymentHandoff,
                );
            }

            return $lockedAttempt->refresh();
        });
    }

    private function recordTimeline(MarketplaceInstallAttempt $attempt, ?string $status, MarketplaceInstallDeploymentData $data): void
    {
        if ($status === null) {
            return;
        }

        RecordMarketplaceInstallAttemptEventAction::run(
            attempt: $attempt,
            level: $this->timelineLevel($status),
            message: (string) __('capell-marketplace::marketplace.operations.' . $this->timelineKey($status)),
            stage: MarketplaceInstallFailureStage::DeploymentHandoff,
            context: [
                'status' => $status,
                'reference' => data_get($data->deployment, 'reference'),
                'type' => data_get($data->deployment, 'type'),
                'fallback' => data_get($data->deployment, 'fallback'),
            ],
        );
    }

    private function deploymentStatus(MarketplaceInstallDeploymentData $data): ?string
    {
        $status = data_get($data->deployment, 'status');

        return is_string($status) && trim($status) !== '' ? $status : null;
    }

    private function failureReason(MarketplaceInstallDeploymentData $data): ?string
    {
        $reason = data_get($data->deployment, 'failure_reason');

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }

    private function timelineLevel(string $status): MarketplaceInstallAttemptEventLevel
    {
        return match ($status) {
            'published', 'succeeded' => MarketplaceInstallAttemptEventLevel::Success,
            'failed' => MarketplaceInstallAttemptEventLevel::Error,
            'unavailable' => MarketplaceInstallAttemptEventLevel::Warning,
            default => MarketplaceInstallAttemptEventLevel::Info,
        };
    }

    private function timelineKey(string $status): string
    {
        return match ($status) {
            'published', 'succeeded' => 'timeline_deployment_published',
            'failed' => 'timeline_deployment_failed',
            'unavailable' => 'timeline_deployment_unavailable',
            default => 'timeline_deployment_recorded',
        };
    }
}
