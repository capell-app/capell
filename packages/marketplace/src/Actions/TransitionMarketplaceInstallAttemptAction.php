<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceOperationVocabulary;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class TransitionMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    /** @var array<string, list<string>> */
    private const array ALLOWED_TRANSITIONS = [
        'queued' => ['running', 'failed', 'cancelled'],
        'running' => ['succeeded', 'failed', 'timed_out', 'cancel_requested'],
        'cancel_requested' => ['cancelled', 'failed'],
    ];

    public function handle(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $transition): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTransitionIsAllowed($lockedAttempt->status, $transition->toStatus);

            $recordedAt = now();
            $attributes = $this->transitionAttributes($lockedAttempt, $transition, $recordedAt);

            $lockedAttempt->forceFill($attributes)->save();

            RecordMarketplaceInstallAttemptEventAction::run(
                attempt: $lockedAttempt,
                level: $transition->timelineLevel ?? $this->timelineLevel($transition->toStatus),
                message: $transition->timelineMessage ?? $this->timelineMessage($lockedAttempt, $transition),
                stage: $transition->timelineStage ?? $this->timelineStage($lockedAttempt, $transition),
                context: $transition->timelineContext,
                outputExcerpt: $transition->timelineOutputExcerpt,
            );

            return $lockedAttempt->refresh();
        });
    }

    private function assertTransitionIsAllowed(
        MarketplaceInstallIntentStatus $fromStatus,
        MarketplaceInstallIntentStatus $toStatus,
    ): void {
        if (in_array($toStatus->value, self::ALLOWED_TRANSITIONS[$fromStatus->value] ?? [], true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot transition Marketplace install attempt from [%s] to [%s].',
            $fromStatus->value,
            $toStatus->value,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function transitionAttributes(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
        CarbonInterface $recordedAt,
    ): array {
        $attributes = [
            'status' => $transition->toStatus,
        ];

        if ($transition->outputExcerpt !== null) {
            $attributes['output_excerpt'] = $this->excerpt($transition->outputExcerpt);
        }

        if ($transition->errorExcerpt !== null) {
            $attributes['error_excerpt'] = $this->excerpt($transition->errorExcerpt);
        }

        if ($transition->toStatus === MarketplaceInstallIntentStatus::Running) {
            return [
                ...$attributes,
                ...$this->runningAttributes($attempt, $transition, $recordedAt),
            ];
        }

        if ($transition->toStatus === MarketplaceInstallIntentStatus::CancelRequested) {
            return [
                ...$attributes,
                'cancel_requested_at' => $attempt->cancel_requested_at ?? $recordedAt,
            ];
        }

        if ($transition->toStatus === MarketplaceInstallIntentStatus::Succeeded) {
            return [
                ...$attributes,
                ...$this->succeededAttributes($attempt, $transition, $recordedAt),
            ];
        }

        if (in_array($transition->toStatus, [
            MarketplaceInstallIntentStatus::Failed,
            MarketplaceInstallIntentStatus::TimedOut,
        ], true)) {
            return [
                ...$attributes,
                ...$this->failedAttributes($attempt, $transition, $recordedAt),
            ];
        }

        return [
            ...$attributes,
            ...$this->cancelledAttributes($attempt, $transition, $recordedAt),
        ];
    }

    /** @return array<string, mixed> */
    private function runningAttributes(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
        CarbonInterface $recordedAt,
    ): array {
        $stageTelemetry = $attempt->stage_telemetry ?? [];
        $queueTelemetry = is_array($stageTelemetry[MarketplaceInstallFailureStage::Queue->value] ?? null)
            ? $stageTelemetry[MarketplaceInstallFailureStage::Queue->value]
            : [];

        $stageTelemetry[MarketplaceInstallFailureStage::Queue->value] = [
            ...$queueTelemetry,
            'started_at' => $queueTelemetry['started_at'] ?? $recordedAt->toIso8601String(),
            'heartbeat_at' => $recordedAt->toIso8601String(),
        ];

        return [
            'started_at' => $attempt->started_at ?? $recordedAt,
            'heartbeat_at' => $recordedAt,
            'attempt_count' => max($attempt->attempt_count, $transition->attemptCount ?? 0),
            'current_stage' => MarketplaceInstallFailureStage::Queue->value,
            'progress_current' => 0,
            'progress_total' => $transition->progressTotal ?? $attempt->progress_total,
            'stage_telemetry' => $stageTelemetry,
            'failure_reason' => null,
            'failure_type' => null,
            'failure_stage' => null,
            'resolved_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function succeededAttributes(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
        CarbonInterface $recordedAt,
    ): array {
        $deploymentStatus = $this->deploymentStatus($attempt);

        if (! $this->deploymentNeedsAttention($attempt)) {
            return [
                'failure_reason' => null,
                'failure_type' => null,
                'failure_stage' => null,
                'completed_at' => $recordedAt,
                'resolved_at' => $recordedAt,
            ];
        }

        $reason = $transition->failureReason
            ?? $this->deploymentFailureReason($attempt)
            ?? $attempt->failure_reason
            ?? (string) __('capell-marketplace::marketplace.operations.deployment_unavailable');
        $classification = ClassifyMarketplaceInstallFailureAction::run(
            stage: MarketplaceInstallFailureStage::DeploymentHandoff,
            message: $reason,
            deploymentStatus: $deploymentStatus,
        );

        return [
            'failure_reason' => $this->redactedText($reason),
            'failure_type' => $classification['failure_type']->value,
            'failure_stage' => $classification['failure_stage']->value,
            'completed_at' => $recordedAt,
            'resolved_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function failedAttributes(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
        CarbonInterface $recordedAt,
    ): array {
        $reason = $transition->failureReason
            ?? ($transition->toStatus === MarketplaceInstallIntentStatus::TimedOut
                ? (string) __('capell-marketplace::marketplace.operations.composer_timed_out')
                : (string) __('capell-marketplace::marketplace.operations.queue_failed'));
        $classification = ClassifyMarketplaceInstallFailureAction::run(
            stage: $transition->failureStage,
            composerResult: $transition->composerResult,
            message: $reason,
            deploymentStatus: $this->failureDeploymentStatus($attempt, $transition),
        );
        $failureType = $transition->failureType
            ?? ($transition->toStatus === MarketplaceInstallIntentStatus::TimedOut
                ? MarketplaceInstallFailureType::Timeout
                : $classification['failure_type']);

        return [
            'failure_reason' => $this->redactedText($reason),
            'failure_type' => $failureType->value,
            'failure_stage' => $classification['failure_stage']->value,
            'completed_at' => $recordedAt,
            'resolved_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function cancelledAttributes(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
        CarbonInterface $recordedAt,
    ): array {
        if ($transition->failureReason === null) {
            return [
                'failure_reason' => null,
                'failure_type' => null,
                'failure_stage' => null,
                'cancel_requested_at' => $attempt->cancel_requested_at ?? $recordedAt,
                'cancelled_at' => $recordedAt,
                'completed_at' => $recordedAt,
                'resolved_at' => $recordedAt,
            ];
        }

        return [
            'failure_reason' => $this->redactedText($transition->failureReason),
            // A caller that knows which irreversible thing had already happened
            // says so; the stage-derived reading is the fallback for callers
            // that do not. Stated by the caller rather than inferred from the
            // stage alone, because the same stage means different things to
            // different operations — a cancelled uninstall has torn the
            // extension down where a cancelled install has just set it up.
            'failure_type' => ($transition->failureType ?? ($transition->failureStage === MarketplaceInstallFailureStage::Composer
                ? MarketplaceInstallFailureType::CancelledAfterComposer
                : MarketplaceInstallFailureType::Unknown))->value,
            'failure_stage' => ($transition->failureStage ?? MarketplaceInstallFailureStage::Composer)->value,
            'cancel_requested_at' => $attempt->cancel_requested_at ?? $recordedAt,
            'cancelled_at' => $recordedAt,
            'completed_at' => $recordedAt,
            'resolved_at' => null,
        ];
    }

    private function timelineLevel(
        MarketplaceInstallIntentStatus $status,
    ): MarketplaceInstallAttemptEventLevel {
        return match ($status) {
            MarketplaceInstallIntentStatus::Running,
            MarketplaceInstallIntentStatus::CancelRequested => MarketplaceInstallAttemptEventLevel::Info,
            MarketplaceInstallIntentStatus::Succeeded => MarketplaceInstallAttemptEventLevel::Success,
            MarketplaceInstallIntentStatus::Cancelled => MarketplaceInstallAttemptEventLevel::Warning,
            default => MarketplaceInstallAttemptEventLevel::Error,
        };
    }

    private function timelineMessage(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
    ): string {
        $key = match ($transition->toStatus) {
            MarketplaceInstallIntentStatus::Running => 'timeline_running',
            MarketplaceInstallIntentStatus::Succeeded => $this->deploymentNeedsAttention($attempt)
                ? 'timeline_succeeded_deployment_attention'
                : 'timeline_succeeded',
            MarketplaceInstallIntentStatus::TimedOut => 'timeline_composer_timed_out',
            MarketplaceInstallIntentStatus::CancelRequested => 'timeline_cancel_requested',
            MarketplaceInstallIntentStatus::Cancelled => $transition->failureReason === null
                ? 'timeline_cancelled'
                : 'timeline_cancelled_after_composer',
            MarketplaceInstallIntentStatus::Failed => $this->failureTimelineKey($attempt, $transition),
            default => 'timeline_failed',
        };

        // Keyed off the attempt's operation, not off the status alone: the same
        // transition means three different things depending on what is being
        // done, and the timeline is the operator's only record of which.
        return MarketplaceOperationVocabulary::translate($attempt->operation, $key);
    }

    private function failureTimelineKey(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
    ): string {
        $stage = $transition->failureStage
            ?? ClassifyMarketplaceInstallFailureAction::run(
                composerResult: $transition->composerResult,
                message: $transition->failureReason,
                deploymentStatus: $this->failureDeploymentStatus($attempt, $transition),
            )['failure_stage'];

        return match ($stage) {
            MarketplaceInstallFailureStage::Preflight => 'timeline_preflight_failed',
            MarketplaceInstallFailureStage::PackageDiscovery => 'timeline_package_discovery_failed',
            MarketplaceInstallFailureStage::Lifecycle => 'timeline_lifecycle_failed',
            MarketplaceInstallFailureStage::HealthCheck => 'timeline_health_check_failed',
            MarketplaceInstallFailureStage::Queue => 'timeline_queue_failed',
            MarketplaceInstallFailureStage::DeploymentHandoff => 'timeline_deployment_failed',
            default => 'timeline_composer_failed',
        };
    }

    private function timelineStage(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
    ): MarketplaceInstallFailureStage {
        if ($transition->failureStage instanceof MarketplaceInstallFailureStage) {
            return $transition->failureStage;
        }

        return match ($transition->toStatus) {
            MarketplaceInstallIntentStatus::Running,
            MarketplaceInstallIntentStatus::CancelRequested,
            MarketplaceInstallIntentStatus::Cancelled => MarketplaceInstallFailureStage::Queue,
            MarketplaceInstallIntentStatus::Succeeded => $this->deploymentNeedsAttention($attempt)
                ? MarketplaceInstallFailureStage::DeploymentHandoff
                : MarketplaceInstallFailureStage::Lifecycle,
            MarketplaceInstallIntentStatus::TimedOut => MarketplaceInstallFailureStage::Composer,
            default => ClassifyMarketplaceInstallFailureAction::run(
                composerResult: $transition->composerResult,
                message: $transition->failureReason,
                deploymentStatus: $this->failureDeploymentStatus($attempt, $transition),
            )['failure_stage'],
        };
    }

    private function failureDeploymentStatus(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptTransitionData $transition,
    ): ?string {
        if ($transition->failureStage !== MarketplaceInstallFailureStage::DeploymentHandoff) {
            return null;
        }

        return $this->deploymentStatus($attempt);
    }

    private function deploymentNeedsAttention(MarketplaceInstallAttempt $attempt): bool
    {
        return in_array($this->deploymentStatus($attempt), ['failed', 'unavailable'], true);
    }

    private function deploymentStatus(MarketplaceInstallAttempt $attempt): ?string
    {
        $status = data_get($attempt->deployment, 'status');

        return is_string($status) ? $status : null;
    }

    private function deploymentFailureReason(MarketplaceInstallAttempt $attempt): ?string
    {
        $reason = data_get($attempt->deployment, 'failure_reason');

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }

    private function excerpt(string $output): ?string
    {
        $output = trim($output);

        return $output === '' ? null : $this->redactedText(Str::limit($output, 4000, ''));
    }

    private function redactedText(string $text): string
    {
        $redacted = RedactMarketplaceDiagnosticContextAction::run([
            'text' => $text,
        ]);

        return is_string($redacted['text'] ?? null) ? $redacted['text'] : '[redacted]';
    }
}
