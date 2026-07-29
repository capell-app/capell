<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Closure;

final readonly class MarketplaceSelectionReviewData
{
    /**
     * @param  list<MarketplaceSelectionRecordData>  $explicitRecords
     * @param  list<MarketplaceSelectionRecordData>  $dependencyRecords
     * @param  list<MarketplaceSelectionRecordData>  $installRecords
     * @param  list<string>  $installComposerNames
     * @param  list<string>  $dependencyComposerNames
     * @param  list<string>  $missingDependencies
     * @param  list<MarketplaceSelectionBlockedDependencyData>  $blockedDependencies
     * @param  list<MarketplaceSelectionRecordData>  $premiumRecords
     * @param  list<string>  $betaDependencyComposerNames
     * @param  list<array<string, mixed>>  $impactRecords
     */
    public function __construct(
        public array $explicitRecords,
        public array $dependencyRecords,
        public array $installRecords,
        public array $installComposerNames,
        public array $dependencyComposerNames,
        public array $missingDependencies,
        public array $blockedDependencies,
        public array $premiumRecords,
        public int $selectedCount,
        public int $installCount,
        public int $totalCents,
        public bool $hasPremiumRecords,
        public bool $containsBeta,
        public array $betaDependencyComposerNames,
        public array $impactRecords,
        public bool $canInstall,
    ) {}

    /**
     * Export the stable computed property consumed by the Marketplace Livewire UI.
     *
     * @param  Closure(string): string  $failureReasonLabel
     * @param  Closure(string): string  $impactReasonLabel
     * @return array{
     *     explicit_records: list<array<string, mixed>>,
     *     dependency_records: list<array<string, mixed>>,
     *     install_records: list<array<string, mixed>>,
     *     install_composer_names: list<string>,
     *     dependency_composer_names: list<string>,
     *     missing_dependencies: list<string>,
     *     blocked_dependencies: list<array{name: string, composer_name: string, reason: string}>,
     *     premium_records: list<array<string, mixed>>,
     *     selected_count: int,
     *     install_count: int,
     *     total_cents: int,
     *     total_label: string,
     *     has_premium_records: bool,
     *     contains_beta: bool,
     *     beta_dependency_composer_names: list<string>,
     *     impact_records: list<array<string, mixed>>,
     *     can_install: bool
     * }
     */
    public function toComputedArray(
        string $freeTotalLabel,
        string $unknownExtensionLabel,
        Closure $failureReasonLabel,
        Closure $impactReasonLabel,
    ): array {
        return [
            'explicit_records' => $this->recordPayloads($this->explicitRecords),
            'dependency_records' => $this->recordPayloads($this->dependencyRecords),
            'install_records' => $this->recordPayloads($this->installRecords),
            'install_composer_names' => $this->installComposerNames,
            'dependency_composer_names' => $this->dependencyComposerNames,
            'missing_dependencies' => $this->missingDependencies,
            'blocked_dependencies' => array_map(
                static fn (MarketplaceSelectionBlockedDependencyData $dependency): array => $dependency->toComputedArray(
                    $unknownExtensionLabel,
                    $failureReasonLabel,
                ),
                $this->blockedDependencies,
            ),
            'premium_records' => $this->recordPayloads($this->premiumRecords),
            'selected_count' => $this->selectedCount,
            'install_count' => $this->installCount,
            'total_cents' => $this->totalCents,
            'total_label' => $this->totalCents <= 0
                ? $freeTotalLabel
                : '$' . number_format($this->totalCents / 100, 2),
            'has_premium_records' => $this->hasPremiumRecords,
            'contains_beta' => $this->containsBeta,
            'beta_dependency_composer_names' => $this->betaDependencyComposerNames,
            'impact_records' => array_map(
                static function (array $impact) use ($impactReasonLabel, $unknownExtensionLabel): array {
                    $reasonCode = is_string($impact['reason_code'] ?? null)
                        ? $impact['reason_code']
                        : 'dependency';

                    return [
                        'composer_name' => $impact['composer_name'] ?? '',
                        'name' => is_string($impact['name'] ?? null) && $impact['name'] !== ''
                            ? $impact['name']
                            : $unknownExtensionLabel,
                        'direct' => (bool) ($impact['direct'] ?? false),
                        'reason' => $impactReasonLabel($reasonCode),
                        'maturity' => $impact['maturity'] ?? 'released',
                        'entitlement' => $impact['entitlement'] ?? 'included',
                        'operation' => $impact['operation'] ?? 'install',
                        'current_version' => $impact['current_version'] ?? null,
                        'target_version' => $impact['target_version'] ?? null,
                        'migrations' => $impact['migrations'] ?? [],
                        'routes' => $impact['routes'] ?? [],
                        'scheduled_jobs' => $impact['scheduled_jobs'] ?? [],
                        'storage' => $impact['storage'] ?? [],
                        'permissions' => $impact['permissions'] ?? [],
                    ];
                },
                $this->impactRecords,
            ),
            'can_install' => $this->canInstall,
        ];
    }

    /**
     * @param  list<MarketplaceSelectionRecordData>  $records
     * @return list<array<string, mixed>>
     */
    private function recordPayloads(array $records): array
    {
        return array_map(
            static fn (MarketplaceSelectionRecordData $record): array => $record->payload,
            $records,
        );
    }
}
