<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceSelectionRecordProvider;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Data\MarketplaceSelectionBlockedDependencyData;
use Capell\Marketplace\Data\MarketplaceSelectionInputData;
use Capell\Marketplace\Data\MarketplaceSelectionRecordData;
use Capell\Marketplace\Data\MarketplaceSelectionReviewData;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Composer\InstalledVersions;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildMarketplaceSelectionReviewAction
{
    use AsFake;
    use AsObject;

    /** @var list<string> */
    private const array MARKETPLACE_MANAGED_DEPENDENCIES = [
        'capell-app/ai-orchestrator',
        'capell-app/block-library',
        'capell-app/insights',
    ];

    /** @var list<string> */
    private const array PREMIUM_FLOW_BLOCK_REASONS = [
        'account_required',
        'not_connected',
        'email_verification_required',
        'capell_all_required',
    ];

    public function __construct(
        private readonly MarketplaceSelectionRecordProvider $records,
    ) {}

    public function handle(MarketplaceSelectionInputData $input): MarketplaceSelectionReviewData
    {
        $records = $this->recordsByComposerNames($input->selectedComposerNames, $input);
        $explicitRecords = [];

        foreach ($input->selectedComposerNames as $composerName) {
            $record = $records[$composerName] ?? null;

            if ($record instanceof MarketplaceSelectionRecordData && $record->isSelectable()) {
                $explicitRecords[$composerName] = $record;
            }
        }

        $dependencyComposerNames = [];
        $missingDependencies = [];
        $blockedDependencies = [];
        $recordsToInspect = $explicitRecords;

        do {
            $addedDependency = false;
            $unresolvedDependencyComposerNames = [];

            foreach ($recordsToInspect as $record) {
                foreach ($record->requiredDependencies as $dependencyComposerName) {
                    if ($this->dependencyIsSatisfied($dependencyComposerName)) {
                        continue;
                    }

                    if (array_key_exists($dependencyComposerName, $explicitRecords)) {
                        continue;
                    }

                    if (array_key_exists($dependencyComposerName, $dependencyComposerNames)) {
                        continue;
                    }

                    if (! array_key_exists($dependencyComposerName, $records)) {
                        $unresolvedDependencyComposerNames[$dependencyComposerName] = $dependencyComposerName;
                    }
                }
            }

            if ($unresolvedDependencyComposerNames !== []) {
                $records = [
                    ...$records,
                    ...$this->recordsByComposerNames(array_values($unresolvedDependencyComposerNames), $input),
                ];
            }

            foreach ($recordsToInspect as $record) {
                foreach ($record->requiredDependencies as $dependencyComposerName) {
                    if ($this->dependencyIsSatisfied($dependencyComposerName)) {
                        continue;
                    }

                    if (array_key_exists($dependencyComposerName, $explicitRecords)) {
                        continue;
                    }

                    if (array_key_exists($dependencyComposerName, $dependencyComposerNames)) {
                        continue;
                    }

                    $dependencyRecord = $records[$dependencyComposerName] ?? null;

                    if (! $dependencyRecord instanceof MarketplaceSelectionRecordData) {
                        $missingDependencies[] = $dependencyComposerName;

                        continue;
                    }

                    if (! $dependencyRecord->isSelectable()) {
                        $blockedDependencies[$dependencyComposerName] = new MarketplaceSelectionBlockedDependencyData(
                            name: $dependencyRecord->name,
                            composerName: $dependencyComposerName,
                            failureReasonCode: $dependencyRecord->failureReasonCode
                                ?? MarketplaceSelectionRecordData::FAILURE_BLOCKED,
                        );

                        continue;
                    }

                    $dependencyComposerNames[$dependencyComposerName] = $dependencyComposerName;
                    $recordsToInspect[$dependencyComposerName] = $dependencyRecord;
                    $addedDependency = true;
                }
            }
        } while ($addedDependency);

        $dependencyRecords = array_values(array_map(
            static fn (string $composerName): MarketplaceSelectionRecordData => $records[$composerName],
            $dependencyComposerNames,
        ));
        $installRecords = [...array_values($explicitRecords), ...$dependencyRecords];
        $installComposerNames = array_values(array_filter(array_map(
            static fn (MarketplaceSelectionRecordData $record): ?string => $record->composerName !== null && $record->composerName !== '0'
                ? $record->composerName
                : null,
            $installRecords,
        )));
        $premiumRecords = array_values(array_filter(
            $installRecords,
            static fn (MarketplaceSelectionRecordData $record): bool => $record->requiresPremiumFlow,
        ));
        $explicitComposerNameLookup = array_fill_keys(array_keys($explicitRecords), true);

        return new MarketplaceSelectionReviewData(
            explicitRecords: array_values($explicitRecords),
            dependencyRecords: $dependencyRecords,
            installRecords: $installRecords,
            installComposerNames: $installComposerNames,
            dependencyComposerNames: array_values($dependencyComposerNames),
            missingDependencies: array_values(array_unique($missingDependencies)),
            blockedDependencies: array_values($blockedDependencies),
            premiumRecords: $premiumRecords,
            selectedCount: count($explicitRecords),
            installCount: count($installRecords),
            totalCents: array_sum(array_map(
                static fn (MarketplaceSelectionRecordData $record): int => $record->priceCents,
                $installRecords,
            )),
            hasPremiumRecords: $premiumRecords !== [],
            containsBeta: array_any(
                $installRecords,
                static fn (MarketplaceSelectionRecordData $record): bool => $record->isBeta(),
            ),
            betaDependencyComposerNames: array_values(array_filter(array_map(
                static fn (MarketplaceSelectionRecordData $record): ?string => $record->isBeta()
                    && $record->composerName !== null
                    && $record->composerName !== '0'
                        ? $record->composerName
                        : null,
                $dependencyRecords,
            ))),
            impactRecords: array_map(
                static fn (MarketplaceSelectionRecordData $record): array => $record->toImpactArray($explicitComposerNameLookup),
                $installRecords,
            ),
            canInstall: $installRecords !== []
                && $missingDependencies === []
                && $blockedDependencies === [],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(array $payload, bool $canManageExtensions): MarketplaceSelectionRecordData
    {
        return $this->applyPolicy(
            MarketplaceSelectionRecordData::fromPayload($payload),
            $canManageExtensions,
        );
    }

    /**
     * @param  list<string>  $composerNames
     * @return array<string, MarketplaceSelectionRecordData>
     */
    private function recordsByComposerNames(
        array $composerNames,
        MarketplaceSelectionInputData $input,
    ): array {
        return array_map(
            fn (MarketplaceSelectionRecordData $record): MarketplaceSelectionRecordData => $this->applyPolicy(
                $record,
                $input->canManageExtensions,
            ),
            $this->records->selectionRecordsByComposerNames(
                composerNames: $composerNames,
                lockedKind: $input->lockedKind,
                includeLocalExtensionState: $input->includeLocalExtensionState,
            ),
        );
    }

    private function applyPolicy(
        MarketplaceSelectionRecordData $record,
        bool $canManageExtensions,
    ): MarketplaceSelectionRecordData {
        return $record->withPolicy(
            requiresPremiumFlow: $this->requiresPremiumFlow($record->payload),
            failureReasonCode: $this->failureReasonCode($record->payload, $canManageExtensions),
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function failureReasonCode(array $record, bool $canManageExtensions): ?string
    {
        if ((bool) ($record['is_installed'] ?? false)) {
            return MarketplaceSelectionRecordData::FAILURE_INSTALLED;
        }

        if ((bool) ($record['install_in_progress'] ?? false)) {
            return MarketplaceSelectionRecordData::FAILURE_INSTALL_IN_PROGRESS;
        }

        if (! (bool) ($record['is_compatible'] ?? true)) {
            return MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE;
        }

        if (! $canManageExtensions) {
            return MarketplaceSelectionRecordData::FAILURE_PERMISSION;
        }

        $installState = is_string($record['marketplace_install_state'] ?? null)
            ? $record['marketplace_install_state']
            : null;

        if (! in_array($installState, ['blocked', 'incompatible'], true)) {
            return null;
        }

        $blockReason = $this->blockReason($record);

        if (in_array($blockReason, self::PREMIUM_FLOW_BLOCK_REASONS, true)) {
            return null;
        }

        return $blockReason ?? MarketplaceSelectionRecordData::FAILURE_UNAVAILABLE;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function requiresPremiumFlow(array $record): bool
    {
        $installState = is_string($record['marketplace_install_state'] ?? null)
            ? $record['marketplace_install_state']
            : null;
        $blockReason = $this->blockReason($record);

        return (bool) ($record['is_paid'] ?? false)
            || (bool) ($record['activation_required'] ?? false)
            || in_array($blockReason, self::PREMIUM_FLOW_BLOCK_REASONS, true)
            || in_array($installState, ['purchase_required', 'activation_required'], true)
            || (is_numeric($record['price_cents'] ?? null) && (int) $record['price_cents'] > 0);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function blockReason(array $record): ?string
    {
        $eligibility = MarketplaceInstallEligibilityData::fromPayload(
            $record['install_eligibility_policy']
                ?? $record['install_eligibility']
                ?? $record['eligibility']
                ?? null,
            protectedInstall: (bool) ($record['is_paid'] ?? false)
                || (bool) ($record['activation_required'] ?? false),
        );

        if ($eligibility->state === MarketplaceInstallState::Incompatible) {
            return MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE;
        }

        if ($eligibility->blocksInstall()) {
            return $eligibility->blockReason ?? MarketplaceSelectionRecordData::FAILURE_BLOCKED;
        }

        $installState = is_string($record['marketplace_install_state'] ?? null)
            ? MarketplaceInstallState::tryFrom($record['marketplace_install_state'])
            : null;
        $purchaseUrl = $record['purchase_url'] ?? null;

        if ($installState === MarketplaceInstallState::Incompatible) {
            return MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE;
        }

        if ($installState === MarketplaceInstallState::PurchaseRequired
            && (! is_string($purchaseUrl) || $purchaseUrl === '')) {
            return 'checkout_unavailable';
        }

        return null;
    }

    private function dependencyIsSatisfied(string $composerName): bool
    {
        if (in_array($composerName, self::MARKETPLACE_MANAGED_DEPENDENCIES, true)) {
            return true;
        }

        foreach (ExtensionListingData::localPackageComposerNameCandidates($composerName) as $candidateComposerName) {
            try {
                if (InstalledVersions::isInstalled($candidateComposerName)) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }
}
