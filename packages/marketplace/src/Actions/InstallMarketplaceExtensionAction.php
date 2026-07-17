<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Data\MarketplaceInstallRequestData;
use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Exceptions\PurchaseRequiredException;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Filament\Support\MarketplaceInstallActionPresenter;
use Capell\Marketplace\Jobs\SendMarketplaceInstallTelemetryJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Filament\Actions\Action as FilamentAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class InstallMarketplaceExtensionAction
{
    use AsFake;
    use AsObject;

    private ?MarketplaceInstallPolicyEvidenceData $activePolicyEvidence = null;

    private ?MarketplaceInstallRequestData $activeRequest = null;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplaceConnectionFormModel $connection,
        private readonly MarketplaceInstallActionPresenter $presenter,
    ) {}

    public function handle(MarketplaceInstallRequestData $request): ?string
    {
        $this->activeRequest = $request;
        $arguments = [
            'slug' => $request->extensionSlug,
            'composer_name' => $request->options['composer_name'] ?? null,
            'install_eligibility_policy' => $request->options['install_eligibility_policy'] ?? null,
        ];
        $data = $request->options;
        $redirectAccountActions = ($request->options['_redirect_account_actions'] ?? false) === true;
        $this->activePolicyEvidence = null;
        $listing = $this->freshListingForInstall($arguments);

        if (! $listing instanceof ExtensionListingData) {
            Notification::make()
                ->title((string) __('capell-marketplace::marketplace.install.not_found'))
                ->warning()
                ->send();

            return null;
        }

        $selectedInstallOptions = $this->selectedInstallOptionsFromData($listing, $data);
        $betaAcknowledged = $request->betaAcknowledged;

        if ($betaAcknowledged) {
            $selectedInstallOptions['beta_acknowledged'] = true;
        }

        $dependencyMaturity = ResolveMarketplaceDependencyMaturityAction::run($listing);
        $policyReason = $this->maturityPolicyReason($listing, $dependencyMaturity, $betaAcknowledged);
        $blockingDependency = match ($policyReason) {
            'beta_dependency_acknowledgement_required' => array_search('beta', $dependencyMaturity, true),
            'incompatible' => array_search('missing', $dependencyMaturity, true),
            default => null,
        };
        $this->activePolicyEvidence = BuildMarketplaceInstallPolicyEvidenceAction::run(
            listing: $listing,
            dependencyMaturity: $dependencyMaturity,
            compatibilityAllowed: $policyReason !== 'incompatible',
            consentAllowed: $policyReason === null,
            reason: $policyReason,
            blockingDependency: is_string($blockingDependency) ? $blockingDependency : null,
        );

        if (is_string($policyReason)) {
            $eligibility = new MarketplaceInstallEligibilityData(
                state: MarketplaceInstallState::Blocked,
                blockReason: $policyReason,
                metadata: array_filter([
                    'reason' => $policyReason,
                    'dependency' => is_string($blockingDependency) ? $blockingDependency : null,
                ]),
            );
            $this->recordBlockedAttempt($listing, $selectedInstallOptions, $eligibility);
            $this->presenter->sendBlockedNotification([
                ...$arguments,
                'install_eligibility_policy' => $eligibility->toArray(),
            ]);

            return null;
        }

        $eligibility = $this->installEligibilityData($listing, $arguments);

        if ($eligibility->blocksInstall()) {
            $this->recordBlockedAttempt($listing, $selectedInstallOptions, $eligibility);

            if ($redirectAccountActions && $this->shouldRedirectForMarketplaceAccountAction($eligibility)) {
                return $this->connection->startAccountConnection();
            }

            $this->presenter->sendBlockedNotification([
                ...$arguments,
                'install_eligibility_policy' => $eligibility->toArray(),
            ]);

            return null;
        }

        try {
            $acquisition = CreateExtensionAcquisitionAction::run(
                listing: $listing,
                licenseKey: $data['license_key'] ?? null,
                email: $data['email'] ?? null,
                installOptions: $selectedInstallOptions,
            );
        } catch (PurchaseRequiredException $exception) {
            $this->handlePurchaseRequired($exception, $listing, $arguments, $selectedInstallOptions, $eligibility);

            return null;
        } catch (Throwable $throwable) {
            $this->handleAuthorizationFailure($throwable, $listing, $arguments, $selectedInstallOptions, $eligibility);

            return null;
        }

        $authorizationEligibility = $acquisition->authorizationEligibilityPolicy;

        if ($this->authorizationBlocksInstall($authorizationEligibility)) {
            $this->recordAuthorizationBlockedAttempt($listing, $acquisition, $selectedInstallOptions, $authorizationEligibility);

            if ($redirectAccountActions && $authorizationEligibility instanceof MarketplaceInstallEligibilityData && $this->shouldRedirectForMarketplaceAccountAction($authorizationEligibility)) {
                return $this->connection->startAccountConnection();
            }

            $this->presenter->sendBlockedNotification([
                ...$arguments,
                'install_eligibility_policy' => $authorizationEligibility?->toArray(),
            ]);

            return null;
        }

        $installAttempt = $this->queueInstallAttempt($listing, $acquisition, $eligibility, $selectedInstallOptions);
        $publishedComposerChange = is_array($installAttempt->refresh()->deployment)
            ? $installAttempt->deployment
            : ['status' => 'unavailable', 'fallback' => 'composer_command'];
        $composerReference = is_string($publishedComposerChange['reference'] ?? null)
            ? $publishedComposerChange['reference']
            : $acquisition->composerCommand;

        if (! $this->requiresMarketplaceAuthorization($listing)) {
            dispatch(new SendMarketplaceInstallTelemetryJob((int) $installAttempt->getKey()));
        }

        if ($listing->kind === ExtensionKind::Theme->value) {
            RecordThemeInstallIntentAction::run(
                extensionSlug: $listing->slug,
                extensionName: $listing->name,
                composerName: $acquisition->composerName,
                composerCommand: $acquisition->composerCommand,
                versionConstraint: $acquisition->versionConstraint,
                imageUrl: $listing->imageUrl,
                description: $listing->description,
                metadata: $this->authorizationLedgerSummary($acquisition),
            );
        }

        $this->sendInstallNotifications(
            composerCommand: $acquisition->composerCommand,
            deploymentReference: $composerReference,
            installAttempt: $installAttempt,
            listing: $listing,
            publishedComposerChange: $publishedComposerChange,
        );

        return null;
    }

    /** @param array<string, string> $dependencyMaturity */
    private function maturityPolicyReason(
        ExtensionListingData $listing,
        array $dependencyMaturity,
        bool $betaAcknowledged,
    ): ?string {
        if (in_array('missing', $dependencyMaturity, true)) {
            return 'incompatible';
        }

        if ($betaAcknowledged) {
            return null;
        }

        if ($listing->maturity === 'beta') {
            return 'beta_acknowledgement_required';
        }

        return in_array('beta', $dependencyMaturity, true)
            ? 'beta_dependency_acknowledgement_required'
            : null;
    }

    /** @param array<string, mixed> $arguments */
    private function freshListingForInstall(array $arguments): ?ExtensionListingData
    {
        $composerName = $arguments['composer_name'] ?? null;

        if (is_string($composerName) && $composerName !== '') {
            return $this->marketplace->extensionsByComposerNames([$composerName], allowCache: false)[$composerName] ?? null;
        }

        return $this->marketplace->getExtension((string) $arguments['slug'], allowCache: false);
    }

    /**
     * @param  array<string, mixed>  $selectedInstallOptions
     */
    private function recordBlockedAttempt(
        ExtensionListingData $listing,
        array $selectedInstallOptions,
        MarketplaceInstallEligibilityData $eligibility,
    ): void {
        RecordMarketplaceInstallAttemptAction::run(
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            composerName: $listing->composerName,
            kind: $listing->kind,
            status: MarketplaceInstallIntentStatus::Blocked,
            betaAcknowledged: $this->betaAcknowledged($selectedInstallOptions),
            policyEvidence: $this->policyEvidence($listing, $selectedInstallOptions, $eligibility->blockReason ?? 'blocked'),
            actor: $this->installActor(),
            source: $this->activeRequest?->source ?? MarketplaceInstallSource::Programmatic,
            requestedOptions: $selectedInstallOptions,
            eligibility: $eligibility->toArray(),
            context: $this->installAttemptContext(),
            failureReason: $eligibility->blockReason ?? 'blocked',
            user: auth()->user(),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $selectedInstallOptions
     */
    private function handlePurchaseRequired(
        PurchaseRequiredException $exception,
        ExtensionListingData $listing,
        array $arguments,
        array $selectedInstallOptions,
        MarketplaceInstallEligibilityData $eligibility,
    ): void {
        Log::info('capell-marketplace: marketplace purchase required', [
            'slug' => $arguments['slug'] ?? null,
            'purchase_url' => $exception->purchaseUrl,
        ]);

        $purchaseUrl = $this->presenter->purchaseUrlWithContext($exception->purchaseUrl, [
            'composer_name' => $listing->composerName,
        ]);

        $notification = Notification::make('install-error')
            ->title((string) __('capell-marketplace::marketplace.install.purchase_required'))
            ->body($exception->getMessage())
            ->warning()
            ->persistent();

        if ($purchaseUrl !== null) {
            $notification->actions([
                FilamentAction::make('openPluginCheckout')
                    ->label((string) __('capell-marketplace::marketplace.install.purchase_button'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->size(Size::Large)
                    ->link()
                    ->url($purchaseUrl, shouldOpenInNewTab: true),
            ]);
        }

        $notification->send();

        RecordMarketplaceInstallAttemptAction::run(
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            composerName: $listing->composerName,
            kind: $listing->kind,
            status: MarketplaceInstallIntentStatus::Blocked,
            betaAcknowledged: $this->betaAcknowledged($selectedInstallOptions),
            policyEvidence: $this->policyEvidence($listing, $selectedInstallOptions, 'entitlement_required'),
            actor: $this->installActor(),
            source: $this->activeRequest?->source ?? MarketplaceInstallSource::Programmatic,
            requestedOptions: $selectedInstallOptions,
            eligibility: [
                ...$eligibility->toArray(),
                'decision' => MarketplaceInstallState::PurchaseRequired->value,
            ],
            context: $this->installAttemptContext(),
            failureReason: $exception->getMessage(),
            user: auth()->user(),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $selectedInstallOptions
     */
    private function handleAuthorizationFailure(
        Throwable $throwable,
        ExtensionListingData $listing,
        array $arguments,
        array $selectedInstallOptions,
        MarketplaceInstallEligibilityData $eligibility,
    ): void {
        Log::warning('capell-marketplace: marketplace install failed', [
            'slug' => $arguments['slug'] ?? null,
            'error' => $throwable->getMessage(),
        ]);

        $notification = Notification::make(MarketplaceConnectionFormModel::INSTALL_FAILED_NOTIFICATION_ID)
            ->title((string) __('capell-marketplace::marketplace.install.failed'))
            ->body($this->installFailureBody($throwable))
            ->danger();

        if ($throwable->getMessage() === MarketplaceClient::INSTANCE_NOT_REGISTERED_MESSAGE) {
            $notification
                ->actions([
                    FilamentAction::make('connectMarketplace')
                        ->label((string) __('capell-marketplace::marketplace.marketplace.connect_button'))
                        ->icon(Heroicon::OutlinedLink)
                        ->color('danger')
                        ->link()
                        ->close()
                        ->dispatch('open-marketplace'),
                ])
                ->persistent();
        }

        $notification->send();

        RecordMarketplaceInstallAttemptAction::run(
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            composerName: $listing->composerName,
            kind: $listing->kind,
            status: MarketplaceInstallIntentStatus::AuthorizationFailed,
            betaAcknowledged: $this->betaAcknowledged($selectedInstallOptions),
            policyEvidence: $this->policyEvidence($listing, $selectedInstallOptions, 'authorization_failed'),
            actor: $this->installActor(),
            source: $this->activeRequest?->source ?? MarketplaceInstallSource::Programmatic,
            requestedOptions: $selectedInstallOptions,
            eligibility: $eligibility->toArray(),
            context: $this->installAttemptContext(),
            failureReason: $throwable->getMessage(),
            user: auth()->user(),
        );
    }

    /**
     * @param  array<string, mixed>  $selectedInstallOptions
     */
    private function recordAuthorizationBlockedAttempt(
        ExtensionListingData $listing,
        ExtensionAcquisitionData $acquisition,
        array $selectedInstallOptions,
        ?MarketplaceInstallEligibilityData $authorizationEligibility,
    ): void {
        RecordMarketplaceInstallAttemptAction::run(
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            composerName: $acquisition->composerName,
            kind: $listing->kind,
            status: MarketplaceInstallIntentStatus::Blocked,
            betaAcknowledged: $this->betaAcknowledged($selectedInstallOptions),
            policyEvidence: $this->policyEvidence($listing, $selectedInstallOptions, $authorizationEligibility?->blockReason ?? 'blocked'),
            actor: $this->installActor(),
            source: $this->activeRequest?->source ?? MarketplaceInstallSource::Programmatic,
            composerCommand: $acquisition->composerCommand,
            versionConstraint: $acquisition->versionConstraint,
            requestedOptions: $selectedInstallOptions,
            eligibility: $authorizationEligibility?->toArray() ?? [],
            context: $this->installAttemptContext(),
            failureReason: $authorizationEligibility?->blockReason ?? $authorizationEligibility?->decision() ?? 'blocked',
            user: auth()->user(),
        );
    }

    /**
     * @param  array<string, mixed>  $selectedInstallOptions
     */
    private function queueInstallAttempt(
        ExtensionListingData $listing,
        ExtensionAcquisitionData $acquisition,
        MarketplaceInstallEligibilityData $eligibility,
        array $selectedInstallOptions,
    ): MarketplaceInstallAttempt {
        return QueueMarketplaceInstallAttemptAction::run(
            listing: $listing,
            acquisition: $acquisition,
            eligibility: $eligibility,
            betaAcknowledged: $this->betaAcknowledged($selectedInstallOptions),
            policyEvidence: $this->policyEvidence($listing, $selectedInstallOptions),
            actor: $this->installActor(),
            source: $this->activeRequest?->source ?? MarketplaceInstallSource::Programmatic,
            requestedOptions: $selectedInstallOptions,
            context: $this->installAttemptContext(),
            deploymentMetadata: [
                'authorization' => $this->authorizationLedgerSummary($acquisition),
                'image_url' => $listing->imageUrl,
                'description' => $listing->description,
            ],
            telemetryStatus: $this->requiresMarketplaceAuthorization($listing) ? null : 'pending',
            user: auth()->user(),
        );
    }

    /** @param array<string, mixed> $selectedInstallOptions */
    private function betaAcknowledged(array $selectedInstallOptions): bool
    {
        return ($selectedInstallOptions['beta_acknowledged'] ?? false) === true;
    }

    /** @param array<string, mixed> $selectedInstallOptions */
    private function policyEvidence(
        ExtensionListingData $listing,
        array $selectedInstallOptions,
        ?string $reason = null,
    ): MarketplaceInstallPolicyEvidenceData {
        if ($this->activePolicyEvidence instanceof MarketplaceInstallPolicyEvidenceData) {
            return new MarketplaceInstallPolicyEvidenceData(
                listingFingerprint: $this->activePolicyEvidence->listingFingerprint,
                listingFetchedAt: $this->activePolicyEvidence->listingFetchedAt,
                selectedMaturity: $this->activePolicyEvidence->selectedMaturity,
                dependencyMaturity: $this->activePolicyEvidence->dependencyMaturity,
                entitlementAllowed: ! in_array($reason, [
                    'account_required',
                    'email_verification_required',
                    'entitlement_required',
                    'purchase_required',
                ], true),
                compatibilityAllowed: $reason !== 'incompatible'
                    && $this->activePolicyEvidence->compatibilityAllowed,
                consentAllowed: $this->activePolicyEvidence->consentAllowed,
                reason: $reason ?? $this->activePolicyEvidence->reason,
                blockingDependency: $this->activePolicyEvidence->blockingDependency,
            );
        }

        $acknowledged = $this->betaAcknowledged($selectedInstallOptions);

        return BuildMarketplaceInstallPolicyEvidenceAction::run(
            listing: $listing,
            consentAllowed: $listing->maturity !== 'beta' || $acknowledged,
            reason: $reason,
        );
    }

    private function installActor(): MarketplaceInstallActorData
    {
        $user = auth()->user();

        return $this->activeRequest?->actor
            ?? ($user instanceof Authenticatable
                ? MarketplaceInstallActorData::fromAuthenticatable($user)
                : MarketplaceInstallActorData::system());
    }

    private function authorizationBlocksInstall(?MarketplaceInstallEligibilityData $eligibility): bool
    {
        if (! $eligibility instanceof MarketplaceInstallEligibilityData) {
            return false;
        }

        if ($eligibility->blocksInstall()) {
            return true;
        }

        if ($eligibility->state === MarketplaceInstallState::PurchaseRequired) {
            return true;
        }

        return $eligibility->state === MarketplaceInstallState::ActivationRequired;
    }

    private function shouldRedirectForMarketplaceAccountAction(MarketplaceInstallEligibilityData $eligibility): bool
    {
        return in_array($eligibility->blockReason, ['account_required', 'not_connected', 'email_verification_required'], true);
    }

    /** @return array<string, mixed> */
    private function installAttemptContext(): array
    {
        $instance = $this->connection->instance();

        return array_filter([
            'connection_state' => $this->connection->connectionState(),
            'instance_id' => $instance?->instance_id,
            'account_id' => $instance?->account_id,
        ], fn (mixed $value): bool => ! in_array($value, [null, [], ''], true));
    }

    private function requiresMarketplaceAuthorization(ExtensionListingData $listing): bool
    {
        if ($listing->isPaid || $listing->activationRequired) {
            return true;
        }

        $eligibility = $listing->installEligibilityPolicy;

        return $eligibility instanceof MarketplaceInstallEligibilityData
            && (
                $eligibility->blocksInstall()
                || $eligibility->state === MarketplaceInstallState::PurchaseRequired
                || $eligibility->state === MarketplaceInstallState::ActivationRequired
            );
    }

    /** @return array<string, mixed> */
    private function authorizationLedgerSummary(ExtensionAcquisitionData $acquisition): array
    {
        $signedActivationId = $acquisition->signedActivation['activation_id'] ?? null;
        $signedActivationExpiresAt = $acquisition->signedActivation['expires_at'] ?? null;

        return array_filter([
            'signed_activation_id' => is_scalar($signedActivationId) ? (string) $signedActivationId : null,
            'signed_activation_expires_at' => is_scalar($signedActivationExpiresAt) ? (string) $signedActivationExpiresAt : null,
            'signed_activation_present' => $acquisition->signedActivation !== [],
            'metadata_keys' => array_keys($acquisition->metadata),
        ], fn (mixed $value): bool => ! in_array($value, [null, [], ''], true));
    }

    /** @param array{status: string, reference?: string, type?: string, failure_reason?: string, fallback?: string}|null $publishedComposerChange */
    private function installNotificationTitle(?array $publishedComposerChange): string
    {
        return match ($publishedComposerChange['status'] ?? 'unavailable') {
            'published' => (string) __('capell-marketplace::marketplace.install.composer_sync_ready'),
            'failed' => (string) __('capell-marketplace::marketplace.install.composer_sync_failed'),
            default => (string) __('capell-marketplace::marketplace.install.local_queued'),
        };
    }

    /**
     * @param  array{status: string, reference?: string, type?: string, failure_reason?: string, fallback?: string}|null  $publishedComposerChange
     */
    private function installNotificationBody(
        string $composerCommand,
        string $deploymentReference,
        ExtensionListingData $listing,
        ?array $publishedComposerChange,
    ): string {
        $body = match ($publishedComposerChange['status'] ?? 'unavailable') {
            'published' => (string) __('capell-marketplace::marketplace.install.composer_sync_ready_body', [
                'name' => $listing->name,
                'reference' => $deploymentReference,
            ]),
            'failed' => (string) __('capell-marketplace::marketplace.install.composer_sync_failed_body', [
                'reason' => (string) ($publishedComposerChange['failure_reason'] ?? __('capell-marketplace::marketplace.install.failed')),
                'command' => $composerCommand,
            ]),
            default => (string) __('capell-marketplace::marketplace.install.local_queued_body', [
                'name' => $listing->name,
                'command' => $composerCommand,
            ]),
        };

        if ($listing->kind !== ExtensionKind::Theme->value) {
            return $body;
        }

        return $body . PHP_EOL . PHP_EOL . __('capell-marketplace::marketplace.themes.installed_next_step');
    }

    /**
     * @param  array{status: string, reference?: string, type?: string, failure_reason?: string, fallback?: string}|null  $publishedComposerChange
     */
    private function sendInstallNotifications(
        string $composerCommand,
        string $deploymentReference,
        MarketplaceInstallAttempt $installAttempt,
        ExtensionListingData $listing,
        ?array $publishedComposerChange,
    ): void {
        if (($publishedComposerChange['status'] ?? 'unavailable') === 'published') {
            Notification::make(MarketplaceInstallNotifications::operationId($listing->composerName))
                ->title($this->installNotificationTitle($publishedComposerChange))
                ->body($this->installNotificationBody(
                    composerCommand: $composerCommand,
                    deploymentReference: $deploymentReference,
                    listing: $listing,
                    publishedComposerChange: $publishedComposerChange,
                ))
                ->success()
                ->persistent()
                ->actions($this->installOperationNotificationActions($installAttempt))
                ->send();

            return;
        }

        if (($publishedComposerChange['status'] ?? 'unavailable') === 'unavailable') {
            Notification::make(MarketplaceInstallNotifications::operationId($listing->composerName))
                ->title($this->installNotificationTitle($publishedComposerChange))
                ->body($this->installNotificationBody(
                    composerCommand: $composerCommand,
                    deploymentReference: $deploymentReference,
                    listing: $listing,
                    publishedComposerChange: $publishedComposerChange,
                ))
                ->success()
                ->persistent()
                ->actions($this->installOperationNotificationActions($installAttempt))
                ->send();

            return;
        }

        Notification::make(MarketplaceInstallNotifications::operationId($listing->composerName))
            ->title((string) __('capell-marketplace::marketplace.install.local_queued'))
            ->body($this->withThemeNextStep(
                body: (string) __('capell-marketplace::marketplace.install.local_queued_body', [
                    'name' => $listing->name,
                    'command' => $composerCommand,
                ]),
                listing: $listing,
            ))
            ->success()
            ->persistent()
            ->actions($this->installOperationNotificationActions($installAttempt))
            ->send();

        Notification::make()
            ->title($this->installNotificationTitle($publishedComposerChange))
            ->body($this->installNotificationBody(
                composerCommand: $composerCommand,
                deploymentReference: $deploymentReference,
                listing: $listing,
                publishedComposerChange: $publishedComposerChange,
            ))
            ->warning()
            ->persistent()
            ->send();
    }

    /** @return array<int, FilamentAction> */
    private function installOperationNotificationActions(MarketplaceInstallAttempt $installAttempt): array
    {
        return [
            FilamentAction::make('viewMarketplaceInstallOperation')
                ->label((string) __('capell-marketplace::marketplace.install.check_operation'))
                ->icon(Heroicon::OutlinedQueueList)
                ->link()
                ->close()
                ->url(MarketplacePackageOperationsPage::getUrl([
                    'tab' => 'active',
                    'operation' => $installAttempt->getKey(),
                ])),
        ];
    }

    private function withThemeNextStep(string $body, ExtensionListingData $listing): string
    {
        if ($listing->kind !== ExtensionKind::Theme->value) {
            return $body;
        }

        return $body . PHP_EOL . PHP_EOL . __('capell-marketplace::marketplace.themes.installed_next_step');
    }

    private function installFailureBody(Throwable $throwable): string
    {
        $message = $throwable->getMessage();

        if ($message === MarketplaceClient::INSTANCE_NOT_REGISTERED_MESSAGE) {
            return (string) __('capell-marketplace::marketplace.install.blocked.not_connected.body');
        }

        return $message !== ''
            ? $message
            : (string) __('capell-marketplace::marketplace.install.failed');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function selectedInstallOptionsFromData(ExtensionListingData $listing, array $data): array
    {
        $selected = is_array($data['install_options'] ?? null)
            ? $data['install_options']
            : [];

        return collect($listing->installOptions)
            ->mapWithKeys(function (array $option) use ($selected): array {
                $key = $option['key'] ?? null;

                if (! is_string($key) || $key === '' || ! array_key_exists($key, $selected)) {
                    return [];
                }

                return [$key => $selected[$key]];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function installEligibilityData(ExtensionListingData $listing, array $arguments): MarketplaceInstallEligibilityData
    {
        $listingPolicy = $listing->installEligibilityPolicy;
        $payload = ($listingPolicy instanceof MarketplaceInstallEligibilityData
            && ($listingPolicy->state instanceof MarketplaceInstallState || $listingPolicy->missingPolicy || $listingPolicy->metadata !== []))
            ? $listingPolicy->toArray()
            : null;

        $protectedInstall = $listing->isPaid || $listing->activationRequired;

        $payload ??= $listing->installEligibility
            ?? ($protectedInstall ? null : ($arguments['install_eligibility_policy'] ?? null))
            ?? $arguments['install_eligibility']
            ?? $arguments['eligibility']
            ?? ($protectedInstall ? null : $listing->installState)
            ?? null;

        $remoteEligibility = MarketplaceInstallEligibilityData::fromPayload(
            $payload,
            protectedInstall: $protectedInstall,
        );

        return ResolveMarketplaceInstallEligibilityAction::run(
            listing: $listing,
            instance: $this->connection->instance(),
            action: 'install',
            remoteEligibility: $remoteEligibility,
        );
    }
}
