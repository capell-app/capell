<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\CreateMarketplaceInstallFlowSessionData;
use Capell\Marketplace\Data\MarketplaceInstallProgressQueryData;
use Capell\Marketplace\Data\MarketplaceInstallRequestData;
use Capell\Marketplace\Data\MarketplaceReviewedSelectionInputData;
use Capell\Marketplace\Data\MarketplaceReviewedSelectionOutcomeData;
use Capell\Marketplace\Data\MarketplaceSelectionBlockedDependencyData;
use Capell\Marketplace\Data\MarketplaceSelectionRecordData;
use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Enums\MarketplaceReviewedSelectionOutcome;
use Capell\Marketplace\Support\MarketplaceTrustedUrlPolicy;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class StartReviewedMarketplaceSelectionAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceTrustedUrlPolicy $trustedUrls) {}

    public function handle(MarketplaceReviewedSelectionInputData $input): MarketplaceReviewedSelectionOutcomeData
    {
        if (! $input->readiness->canInstallAutomatically()
            || ! $input->selection->canInstall
            || ! $input->confirmed
            || ($input->selection->containsBeta && ! $input->betaAcknowledged)
        ) {
            return new MarketplaceReviewedSelectionOutcomeData(
                MarketplaceReviewedSelectionOutcome::Rejected,
            );
        }

        if ($this->selectionRequiresLicenceKey($input)) {
            if ($input->licenseKey === null || trim($input->licenseKey) === '') {
                return new MarketplaceReviewedSelectionOutcomeData(
                    outcome: MarketplaceReviewedSelectionOutcome::LicenceValidationFailed,
                    licenceValidationRule: 'required',
                );
            }

            if (mb_strlen($input->licenseKey) > 512) {
                return new MarketplaceReviewedSelectionOutcomeData(
                    outcome: MarketplaceReviewedSelectionOutcome::LicenceValidationFailed,
                    licenceValidationRule: 'max',
                );
            }
        }

        if ($this->selectionNeedsHostedFlow($input)) {
            try {
                $redirectUrl = StartMarketplaceInstallFlowAction::run(
                    $this->installFlowSession($input),
                );

                return new MarketplaceReviewedSelectionOutcomeData(
                    outcome: MarketplaceReviewedSelectionOutcome::HostedFlowRedirect,
                    redirectUrl: $redirectUrl,
                );
            } catch (Throwable $throwable) {
                $fallbackUrl = $this->fallbackPurchaseUrl($input->selection->premiumRecords);

                if ($fallbackUrl !== null) {
                    return new MarketplaceReviewedSelectionOutcomeData(
                        outcome: MarketplaceReviewedSelectionOutcome::PurchaseFallback,
                        redirectUrl: $fallbackUrl,
                    );
                }

                return new MarketplaceReviewedSelectionOutcomeData(
                    outcome: MarketplaceReviewedSelectionOutcome::PresentableFailure,
                    failure: $throwable,
                );
            }
        }

        foreach ($input->selection->installRecords as $record) {
            try {
                $redirectUrl = InstallMarketplaceExtensionAction::run(
                    $this->installRequest($record, $input),
                );
            } catch (ValidationException $validationException) {
                $message = collect($validationException->errors())->flatten()->first();

                return new MarketplaceReviewedSelectionOutcomeData(
                    outcome: MarketplaceReviewedSelectionOutcome::LicenceValidationFailed,
                    licenceValidationMessage: is_string($message) ? $message : null,
                );
            } catch (Throwable $throwable) {
                return new MarketplaceReviewedSelectionOutcomeData(
                    outcome: MarketplaceReviewedSelectionOutcome::PresentableFailure,
                    failure: $throwable,
                );
            }

            if (is_string($redirectUrl) && $redirectUrl !== '') {
                return new MarketplaceReviewedSelectionOutcomeData(
                    outcome: MarketplaceReviewedSelectionOutcome::AccountActionRedirect,
                    redirectUrl: $redirectUrl,
                );
            }
        }

        $progress = QueryMarketplaceInstallProgressAction::run(
            MarketplaceInstallProgressQueryData::forComposerNames($input->selection->installComposerNames),
        );

        return new MarketplaceReviewedSelectionOutcomeData(
            outcome: MarketplaceReviewedSelectionOutcome::Queued,
            queuedAttemptIds: $progress->attemptIds(),
        );
    }

    private function selectionRequiresLicenceKey(MarketplaceReviewedSelectionInputData $input): bool
    {
        return array_any(
            $input->selection->installRecords,
            fn (MarketplaceSelectionRecordData $record): bool => $this->recordRequiresLicenceKey($record),
        );
    }

    private function selectionNeedsHostedFlow(MarketplaceReviewedSelectionInputData $input): bool
    {
        foreach ($input->selection->premiumRecords as $record) {
            if ((bool) ($record->payload['install_authorized'] ?? false)) {
                continue;
            }

            if ($this->recordRequiresLicenceKey($record) && filled($input->licenseKey)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function recordRequiresLicenceKey(MarketplaceSelectionRecordData $record): bool
    {
        return in_array(MarketplaceInstallState::ActivationRequired->value, [
            $record->payload['marketplace_install_state'] ?? null,
            $record->payload['install_state'] ?? null,
            $record->payload['server_install_state'] ?? null,
            data_get($record->payload, 'install_eligibility_policy.state'),
        ], true);
    }

    private function installFlowSession(MarketplaceReviewedSelectionInputData $input): CreateMarketplaceInstallFlowSessionData
    {
        return new CreateMarketplaceInstallFlowSessionData(
            selectedExtensions: array_values(array_map(
                $this->installFlowSelection(...),
                $input->selection->installRecords,
            )),
            installOptions: [
                ...$this->selectedInstallOptionsByRecord($input),
                'beta_acknowledged' => $input->selection->containsBeta && $input->betaAcknowledged,
            ],
            dependencySnapshot: [
                'missing_dependencies' => $input->selection->missingDependencies,
                'blocked_dependencies' => array_map(
                    static fn (MarketplaceSelectionBlockedDependencyData $dependency): array => [
                        'name' => $dependency->name,
                        'composer_name' => $dependency->composerName,
                        'reason' => $dependency->failureReasonCode,
                    ],
                    $input->selection->blockedDependencies,
                ),
                'dependency_composer_names' => $input->selection->dependencyComposerNames,
            ],
            userContext: [
                'user_id' => $input->actor->system ? null : $input->actor->identifier,
                'user_email' => $input->actor->email,
            ],
            returnUrl: $input->returnUrl,
        );
    }

    /** @return array<string, mixed> */
    private function installFlowSelection(MarketplaceSelectionRecordData $record): array
    {
        return [
            'slug' => $this->recordSlug($record),
            'composer_name' => $record->composerName,
            'name' => $record->name,
            'kind' => is_string($record->payload['kind'] ?? null) ? $record->payload['kind'] : 'tool',
            'price_cents' => $record->priceCents,
            'install_authorized' => (bool) ($record->payload['install_authorized'] ?? false),
            'install_eligibility' => is_array($record->payload['install_eligibility_policy'] ?? null)
                ? $record->payload['install_eligibility_policy']
                : [],
        ];
    }

    private function fallbackPurchaseUrl(array $records): ?string
    {
        foreach ($records as $record) {
            $purchaseUrl = $record->payload['purchase_url'] ?? null;

            if (is_string($purchaseUrl)) {
                $trustedUrl = $this->trustedUrls->trusted($purchaseUrl);

                if ($trustedUrl !== null) {
                    return $trustedUrl;
                }
            }
        }

        return null;
    }

    private function installRequest(
        MarketplaceSelectionRecordData $record,
        MarketplaceReviewedSelectionInputData $input,
    ): MarketplaceInstallRequestData {
        return MarketplaceInstallRequestData::make(
            extensionSlug: $this->recordSlug($record),
            options: [
                'license_key' => $input->licenseKey,
                '_validation_errors' => true,
                'install_options' => [
                    ...$this->selectedInstallOptionsForRecords([$record], $input),
                    ...$this->themeActivationInstallOption($record, $input),
                    'beta_acknowledged' => $input->selection->containsBeta && $input->betaAcknowledged,
                ],
                'composer_name' => $record->composerName,
                'install_eligibility_policy' => $record->payload['install_eligibility_policy'] ?? null,
                '_redirect_account_actions' => true,
            ],
            actor: $input->actor,
            betaAcknowledged: $input->selection->containsBeta && $input->betaAcknowledged,
            source: MarketplaceInstallSource::TableHelper,
        );
    }

    /** @return array<string, bool> */
    private function themeActivationInstallOption(
        MarketplaceSelectionRecordData $record,
        MarketplaceReviewedSelectionInputData $input,
    ): array {
        if (($record->payload['kind'] ?? null) !== ExtensionKind::Theme->value) {
            return [];
        }

        return [
            RecordThemeInstallIntentAction::ACTIVATE_AFTER_INSTALL => $input->activateThemesAfterInstall,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function selectedInstallOptionsByRecord(MarketplaceReviewedSelectionInputData $input): array
    {
        $options = [];

        foreach ($input->selection->installRecords as $record) {
            $recordOptions = $this->selectedInstallOptionsForRecords([$record], $input);

            if ($recordOptions === []) {
                continue;
            }

            if ($record->composerName !== null) {
                $options[$record->composerName] = $recordOptions;
            }

            $slug = $this->recordSlug($record);

            if ($slug !== '') {
                $options[$slug] = $recordOptions;
            }
        }

        return $options;
    }

    /**
     * @param  list<MarketplaceSelectionRecordData>  $records
     * @return array<string, mixed>
     */
    private function selectedInstallOptionsForRecords(
        array $records,
        MarketplaceReviewedSelectionInputData $input,
    ): array {
        $allowedKeys = [];

        foreach ($records as $record) {
            foreach ($this->recordInstallOptions($record) as $option) {
                $key = $option['key'] ?? null;

                if (is_string($key) && $key !== '') {
                    $allowedKeys[$key] = true;
                }
            }
        }

        return array_intersect_key($input->selectedInstallOptions, $allowedKeys);
    }

    /** @return list<array<string, mixed>> */
    private function recordInstallOptions(MarketplaceSelectionRecordData $record): array
    {
        $options = $record->payload['install_options'] ?? [];

        return is_array($options)
            ? array_values(array_filter($options, is_array(...)))
            : [];
    }

    private function recordSlug(MarketplaceSelectionRecordData $record): string
    {
        return is_string($record->payload['slug'] ?? null)
            ? $record->payload['slug']
            : '';
    }
}
