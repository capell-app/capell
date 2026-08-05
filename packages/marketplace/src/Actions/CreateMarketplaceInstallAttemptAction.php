<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CreateMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttemptData $data,
        ?Authenticatable $user = null,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($data, $user): MarketplaceInstallAttempt {
            $recordedAt = now();
            $userContext = $this->userContext($user);
            $context = $data->context;

            if ($data->actor instanceof MarketplaceInstallActorData) {
                $context['install_actor'] = $data->actor->toArray();
            }

            if ($data->source instanceof MarketplaceInstallSource) {
                $context['install_source'] = $data->source->value;
            }

            $attempt = MarketplaceInstallAttempt::query()->create([
                'composer_name' => $data->composerName,
                'extension_slug' => $data->extensionSlug,
                'extension_name' => $data->extensionName,
                'kind' => $data->kind,
                'status' => $data->status,
                'operation' => $data->operation,
                'uninstall_options' => $data->uninstallOptions !== [] ? $data->uninstallOptions : null,
                'composer_command' => $data->composerCommand,
                'version_constraint' => $data->versionConstraint,
                'requested_options' => $data->requestedOptions !== [] ? $data->requestedOptions : null,
                'eligibility' => $data->eligibility !== [] ? $data->eligibility : null,
                'context' => $context !== [] ? $context : null,
                'deployment' => $data->deployment !== [] ? $data->deployment : null,
                'beta_acknowledged' => $data->betaAcknowledged,
                'policy_evidence' => $data->policyEvidence?->toArray(),
                'failure_reason' => $data->failureReason,
                'telemetry_status' => $data->telemetryStatus,
                'idempotency_key' => $this->idempotencyKey($data->idempotencyKey),
                'retry_of_id' => $data->retryOfId,
                'retried_by_id' => $data->retriedById,
                'retried_at' => $data->retriedAt,
                'user_id' => $userContext['id'] ?? $data->userId,
                'user_email' => $userContext['email'] ?? $data->userEmail,
                'queued_at' => $data->initializeLifecycle
                    && $data->status === MarketplaceInstallIntentStatus::Queued
                        ? $recordedAt
                        : null,
                'resolved_at' => $data->initializeLifecycle
                    ? $this->resolvedAt($data->status, $data->failureReason, $recordedAt)
                    : $this->legacyResolvedAt($data->status, $recordedAt),
            ]);

            if ($data->timelineMessage !== null) {
                RecordMarketplaceInstallAttemptEventAction::run(
                    attempt: $attempt,
                    level: $data->timelineLevel ?? MarketplaceInstallAttemptEventLevel::Info,
                    message: $data->timelineMessage,
                    stage: $data->timelineStage,
                    context: $data->timelineContext,
                    outputExcerpt: $data->timelineOutputExcerpt,
                );
            }

            return $attempt;
        });
    }

    private function idempotencyKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return null;
        }

        return hash('sha256', $idempotencyKey);
    }

    private function resolvedAt(
        MarketplaceInstallIntentStatus $status,
        ?string $failureReason,
        CarbonInterface $recordedAt,
    ): ?CarbonInterface {
        if ($status === MarketplaceInstallIntentStatus::Succeeded) {
            return $recordedAt;
        }

        if ($status === MarketplaceInstallIntentStatus::Cancelled && $failureReason === null) {
            return $recordedAt;
        }

        return in_array($status, [
            MarketplaceInstallIntentStatus::CommandFallback,
            MarketplaceInstallIntentStatus::DeploymentPublished,
            MarketplaceInstallIntentStatus::AuthorizationFailed,
            MarketplaceInstallIntentStatus::Blocked,
            MarketplaceInstallIntentStatus::DeploymentFailed,
        ], true) ? $recordedAt : null;
    }

    private function legacyResolvedAt(
        MarketplaceInstallIntentStatus $status,
        CarbonInterface $recordedAt,
    ): ?CarbonInterface {
        return in_array($status, [
            MarketplaceInstallIntentStatus::CommandFallback,
            MarketplaceInstallIntentStatus::DeploymentPublished,
            MarketplaceInstallIntentStatus::AuthorizationFailed,
            MarketplaceInstallIntentStatus::Blocked,
            MarketplaceInstallIntentStatus::DeploymentFailed,
        ], true) ? $recordedAt : null;
    }

    /** @return array{id?: string, email?: string} */
    private function userContext(?Authenticatable $user): array
    {
        if (! $user instanceof Authenticatable) {
            return [];
        }

        $email = method_exists($user, 'getAttribute') ? $user->getAttribute('email') : null;
        $identifier = $user->getAuthIdentifier();

        return array_filter([
            'id' => is_scalar($identifier) ? (string) $identifier : null,
            'email' => is_string($email) ? $email : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
