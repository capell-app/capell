<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class ResumeMarketplaceInstallFlowAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    /**
     * @return array<int, MarketplaceInstallAttempt>
     */
    public function handle(
        MarketplaceInstallFlowSession $session,
        ?MarketplaceInstallActorData $actor = null,
    ): array {
        try {
            $reservedSession = $this->reserveSessionForQueueing($session);
            $attempts = $this->queueInstallAttempts($reservedSession, $actor);
        } catch (Throwable $throwable) {
            MarketplaceInstallFlowSessionTransitionAction::run($session->refresh(), MarketplaceInstallFlowSessionStatus::Failed, $throwable->getMessage());

            throw $throwable;
        }

        MarketplaceInstallFlowSessionTransitionAction::run($session->refresh(), MarketplaceInstallFlowSessionStatus::Completed, 'queued_composer');

        return $attempts;
    }

    private function reserveSessionForQueueing(MarketplaceInstallFlowSession $session): MarketplaceInstallFlowSession
    {
        return DB::transaction(function () use ($session): MarketplaceInstallFlowSession {
            $lockedSession = MarketplaceInstallFlowSession::query()
                ->whereKey($session->getKey())
                ->whereIn('status', [
                    MarketplaceInstallFlowSessionStatus::Returned,
                    MarketplaceInstallFlowSessionStatus::Failed,
                ])
                ->lockForUpdate()
                ->first();

            throw_unless($lockedSession instanceof MarketplaceInstallFlowSession, RuntimeException::class, 'Marketplace install flow session is not ready to resume.');

            $this->assertV2EntitlementsPresent($lockedSession);

            MarketplaceInstallFlowSessionTransitionAction::run($lockedSession, MarketplaceInstallFlowSessionStatus::Queued, 'resume_requested');

            return $lockedSession->refresh();
        }, attempts: 3);
    }

    private function assertV2EntitlementsPresent(MarketplaceInstallFlowSession $session): void
    {
        if ($session->contract_version < 2) {
            return;
        }

        $entitlements = $session->remote_entitlement_ids ?? [];

        foreach (array_values(array_filter($session->quoted_extensions ?? [], is_array(...))) as $quotedExtension) {
            $composerName = $quotedExtension['composer_name'] ?? null;
            if (! is_string($composerName)) {
                continue;
            }

            if ($composerName === '') {
                continue;
            }

            if ((int) ($quotedExtension['price_cents'] ?? 0) <= 0) {
                continue;
            }

            throw_unless(
                isset($entitlements[$composerName]) && is_numeric($entitlements[$composerName]),
                RuntimeException::class,
                sprintf('Marketplace install flow is missing entitlement for [%s].', $composerName),
            );
        }
    }

    /**
     * @return array<int, MarketplaceInstallAttempt>
     */
    private function queueInstallAttempts(
        MarketplaceInstallFlowSession $session,
        ?MarketplaceInstallActorData $actor,
    ): array {
        $attempts = [];

        foreach ($this->selectedExtensions($session) as $selection) {
            $slug = $this->requiredString($selection, 'slug');
            $composerName = $this->requiredString($selection, 'composer_name');

            if ($this->hasActiveOrSuccessfulAttempt($session, $composerName)) {
                continue;
            }

            $listing = $this->marketplace->getExtension($slug, allowCache: false);

            throw_unless($listing instanceof ExtensionListingData, RuntimeException::class, sprintf('Marketplace extension [%s] was not found.', $slug));

            $installOptions = $this->installOptionsFor($session, $listing);
            $acquisition = CreateExtensionAcquisitionAction::run(
                listing: $listing,
                licenseKey: null,
                email: $actor?->email ?? auth()->user()?->email,
                installOptions: $installOptions,
                hostedFlowAuthorized: true,
            );

            $this->assertAuthorizationAllowed($acquisition);

            $attempts[] = QueueMarketplaceInstallAttemptAction::run(
                listing: $listing,
                acquisition: $acquisition,
                eligibility: $listing->installEligibilityPolicy ?? new MarketplaceInstallEligibilityData(
                    state: MarketplaceInstallState::Authorized,
                    canInstall: true,
                    canUpdate: true,
                    canRunExisting: true,
                ),
                betaAcknowledged: ($installOptions['beta_acknowledged'] ?? false) === true,
                policyEvidence: BuildMarketplaceInstallPolicyEvidenceAction::run(
                    listing: $listing,
                    consentAllowed: $listing->maturity !== 'beta' || ($installOptions['beta_acknowledged'] ?? false) === true,
                ),
                actor: $actor ?? (auth()->user() instanceof Authenticatable
                    ? MarketplaceInstallActorData::fromAuthenticatable(auth()->user())
                    : MarketplaceInstallActorData::system('marketplace-hosted-resume')),
                source: MarketplaceInstallSource::HostedResume,
                requestedOptions: $installOptions,
                context: [
                    'source' => 'marketplace_install_flow',
                    'flow_session_id' => $session->getKey(),
                    'remote_flow_id' => $session->remote_flow_id,
                    'remote_entitlement_ids' => $session->remote_entitlement_ids ?? [],
                ],
                deploymentMetadata: [
                    'authorization' => $acquisition->metadata,
                    'image_url' => $listing->imageUrl,
                    'description' => $listing->description,
                ],
                user: auth()->user(),
                afterResponse: false,
                idempotencyKey: sprintf('hosted-flow:%s:%s', $session->getKey(), $composerName),
            );
        }

        return $attempts;
    }

    private function hasActiveOrSuccessfulAttempt(
        MarketplaceInstallFlowSession $session,
        string $composerName,
    ): bool {
        return MarketplaceInstallAttempt::query()
            ->where('composer_name', $composerName)
            ->where('context->remote_flow_id', $session->remote_flow_id)
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::Succeeded->value,
            ])
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectedExtensions(MarketplaceInstallFlowSession $session): array
    {
        return array_values(array_filter($session->selected_extensions ?? [], is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function requiredString(array $selection, string $key): string
    {
        $value = $selection[$key] ?? null;

        throw_unless(is_string($value) && $value !== '', RuntimeException::class, sprintf('Marketplace install flow selection is missing %s.', $key));

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function installOptionsFor(MarketplaceInstallFlowSession $session, ExtensionListingData $listing): array
    {
        $options = $session->install_options ?? [];
        $packageOptions = $options[$listing->composerName] ?? $options[$listing->slug] ?? [];
        $sharedOptions = array_filter(
            $options,
            fn (mixed $value): bool => ! is_array($value),
        );

        return is_array($packageOptions)
            ? [...$sharedOptions, ...$packageOptions]
            : $sharedOptions;
    }

    private function assertAuthorizationAllowed(ExtensionAcquisitionData $acquisition): void
    {
        $eligibility = $acquisition->authorizationEligibilityPolicy;

        if (! $eligibility instanceof MarketplaceInstallEligibilityData) {
            return;
        }

        throw_if(
            $eligibility->blocksInstall()
            || $eligibility->state === MarketplaceInstallState::PurchaseRequired
            || $eligibility->state === MarketplaceInstallState::ActivationRequired,
            RuntimeException::class,
            $eligibility->blockReason ?? $eligibility->decision(),
        );
    }
}
