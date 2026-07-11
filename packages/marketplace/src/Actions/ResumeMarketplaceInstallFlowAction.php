<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

final class ResumeMarketplaceInstallFlowAction
{
    use AsAction;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    /**
     * @return array<int, MarketplaceInstallAttempt>
     */
    public function handle(MarketplaceInstallFlowSession $session): array
    {
        try {
            $reservedSession = $this->reserveSessionForQueueing($session);
            $attempts = $this->queueInstallAttempts($reservedSession);
        } catch (Throwable $throwable) {
            MarketplaceInstallFlowSessionTransitionAction::run($session, MarketplaceInstallFlowSessionStatus::Failed, $throwable->getMessage());

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
    private function queueInstallAttempts(MarketplaceInstallFlowSession $session): array
    {
        $attempts = [];

        foreach ($this->selectedExtensions($session) as $selection) {
            $slug = $this->requiredString($selection, 'slug');
            $listing = $this->marketplace->getExtension($slug, allowCache: false);

            throw_unless($listing instanceof ExtensionListingData, RuntimeException::class, sprintf('Marketplace extension [%s] was not found.', $slug));

            $installOptions = $this->installOptionsFor($session, $listing);
            $acquisition = CreateExtensionAcquisitionAction::run(
                listing: $listing,
                licenseKey: null,
                email: auth()->user()?->email,
                installOptions: $installOptions,
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
            );
        }

        return $attempts;
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

        return is_array($packageOptions) ? $packageOptions : [];
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
