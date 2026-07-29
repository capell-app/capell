<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallDeploymentData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class RecordMarketplaceInstallDeploymentAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallDeploymentData $data,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $data): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedAttempt->status, [
                MarketplaceInstallIntentStatus::Queued,
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

            $lockedAttempt->forceFill($attributes)->save();

            if ($status !== null) {
                RecordMarketplaceInstallAttemptEventAction::run(
                    attempt: $lockedAttempt,
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

            return $lockedAttempt->refresh();
        });
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
