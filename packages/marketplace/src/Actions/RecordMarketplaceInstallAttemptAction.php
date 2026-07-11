<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordMarketplaceInstallAttemptAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $requestedOptions
     * @param  array<string, mixed>  $eligibility
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $deployment
     */
    public function handle(
        string $extensionSlug,
        string $extensionName,
        string $composerName,
        string $kind,
        MarketplaceInstallIntentStatus $status,
        ?string $composerCommand = null,
        ?string $versionConstraint = null,
        array $requestedOptions = [],
        array $eligibility = [],
        array $context = [],
        array $deployment = [],
        ?string $failureReason = null,
        ?string $telemetryStatus = null,
        ?Authenticatable $user = null,
    ): MarketplaceInstallAttempt {
        $recordedAt = now();
        $userContext = $this->userContext($user);

        return MarketplaceInstallAttempt::query()->create([
            'composer_name' => $composerName,
            'extension_slug' => $extensionSlug,
            'extension_name' => $extensionName,
            'kind' => $kind,
            'status' => $status,
            'composer_command' => $composerCommand,
            'version_constraint' => $versionConstraint,
            'requested_options' => $requestedOptions !== [] ? $requestedOptions : null,
            'eligibility' => $eligibility !== [] ? $eligibility : null,
            'context' => $context !== [] ? $context : null,
            'deployment' => $deployment !== [] ? $deployment : null,
            'failure_reason' => $failureReason,
            'telemetry_status' => $telemetryStatus,
            'user_id' => $userContext['id'] ?? null,
            'user_email' => $userContext['email'] ?? null,
            'resolved_at' => in_array($status, [
                MarketplaceInstallIntentStatus::CommandFallback,
                MarketplaceInstallIntentStatus::DeploymentPublished,
                MarketplaceInstallIntentStatus::AuthorizationFailed,
                MarketplaceInstallIntentStatus::Blocked,
                MarketplaceInstallIntentStatus::DeploymentFailed,
            ], true) ? $recordedAt : null,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function userContext(?Authenticatable $user): ?array
    {
        if (! $user instanceof Authenticatable) {
            return null;
        }

        $email = method_exists($user, 'getAttribute') ? $user->getAttribute('email') : null;

        $identifier = $user->getAuthIdentifier();

        return array_filter([
            'id' => is_scalar($identifier) ? (string) $identifier : null,
            'email' => is_string($email) ? $email : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
