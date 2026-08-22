<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Support;

use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceCatalogueRecordData;
use Capell\Marketplace\Enums\MarketplaceExtensionCapability;
use Capell\Marketplace\Enums\MarketplaceExtensionCategory;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

final class MarketplaceCatalogueRecordPresenter
{
    public function __construct(
        private readonly MarketplaceInstallActionPresenter $installActionPresenter,
    ) {}

    /** @return array<string, mixed> */
    public function present(MarketplaceCatalogueRecordData $record): array
    {
        $extension = $record->listing;
        $localState = $record->localState;
        $eligibility = $record->eligibility->toArray();

        return [
            'key' => $extension->slug,
            'slug' => $extension->slug,
            'name' => $extension->name,
            'composer_name' => $extension->composerName,
            'kind' => $extension->kind,
            'product_group' => $extension->productGroup,
            'product_tier' => $extension->productTier,
            'product_bundle' => $extension->productBundle,
            'bundle_label' => $extension->productBundle !== null
                ? (string) __('capell-marketplace::marketplace.suites.bundle_badge', [
                    'bundle' => str($extension->productBundle)->headline(),
                ])
                : null,
            'catalogue_role' => $extension->catalogueRole,
            'maturity' => $extension->maturity,
            'maturity_label' => $extension->maturityLabel,
            'included_with_capell_all' => $extension->includedWithCapellAll,
            'effective_certification' => $extension->effectiveCertification,
            'support_policy' => $extension->supportPolicy,
            'description' => $extension->description,
            'image_url' => $record->imageUrl,
            'image_urls' => $record->imageUrls,
            'price_cents' => $extension->priceCents,
            'currency' => $extension->currency,
            'price_label' => $this->priceLabel($extension),
            'trial' => $extension->trial,
            'trial_label' => $this->trialLabel($extension->trial),
            'is_paid' => $extension->isPaid,
            'is_featured' => $extension->isFeatured,
            'featured_rank' => $extension->featuredRank,
            'is_publisher_verified' => $extension->publisherVerified,
            'is_security_reviewed' => $extension->securityReviewed,
            'latest_version' => $extension->latestVersion,
            'released_at_label' => $extension->releasedAt?->toFormattedDateString(),
            'author_name' => $extension->authorName,
            'author_filter' => $extension->authorSlug ?? $extension->authorName,
            'rating_average' => $extension->ratingAverage,
            'rating_average_label' => $this->ratingAverageLabel($extension->ratingAverage),
            'rating_stars' => $this->ratingStars($extension->ratingAverage),
            'ratings_count' => $extension->ratingsCount,
            'ratings_count_label' => $this->ratingsCountLabel($extension->ratingsCount),
            'is_installed' => $localState->isInstalled,
            'installed_version' => $localState->installedVersion,
            'has_update_available' => $localState->hasUpdateAvailable,
            'documentation_url' => $record->documentationUrl,
            'purchase_url' => $record->purchaseUrl,
            'requires_confirmation' => $extension->requiresConfirmation,
            'install_confirmation' => $extension->installConfirmation,
            'install_options' => $extension->installOptions,
            'required_dependencies' => $extension->requiredDependencies,
            'install_impact' => is_array($extension->metadata['install_impact'] ?? null)
                ? $extension->metadata['install_impact']
                : [],
            'entitlement' => is_string($extension->metadata['entitlement'] ?? null)
                ? $extension->metadata['entitlement']
                : null,
            'capell_version_constraint' => $extension->capellVersionConstraint,
            'laravel_version_constraint' => $extension->laravelVersionConstraint,
            'filament_version_constraint' => $extension->filamentVersionConstraint,
            'livewire_version_constraint' => $extension->livewireVersionConstraint,
            'category_labels' => $this->categoryLabels($extension->categories),
            'capability_labels' => $this->capabilityLabels($extension->capabilities),
            'surface_labels' => $this->stateLabels($extension->surfaces),
            'contribution_count' => array_sum($extension->contributionSummary),
            'is_compatible' => $record->isCompatible,
            'compatibility_warnings' => $this->compatibilityWarnings($record->compatibilityDetails),
            'activation_required' => $extension->activationRequired,
            'server_install_state' => $extension->installState,
            'install_authorized' => $extension->installAuthorized,
            'install_eligibility_policy' => $eligibility,
            'install_in_progress' => $localState->installInProgress(),
            'active_install_operation_id' => $localState->activeOperationId,
            'active_install_operation_status' => $localState->activeOperationStatus?->value,
            'primary_action' => $extension->primaryAction,
            'marketplace_install_state' => $this->installActionPresenter->state([
                'is_installed' => $localState->isInstalled,
                'has_update_available' => $localState->hasUpdateAvailable,
                'is_compatible' => $record->isCompatible,
                'is_paid' => $extension->isPaid,
                'marketplace_install_state' => $extension->installState,
                'activation_required' => $extension->activationRequired,
                'install_authorized' => $extension->installAuthorized,
                'install_eligibility_policy' => $eligibility,
                'purchase_url' => $record->purchaseUrl,
                'install_in_progress' => $localState->installInProgress(),
            ])->value,
        ];
    }

    private function priceLabel(ExtensionListingData $extension): string
    {
        if (! $extension->isPaid || str($extension->productTier ?? '')->lower()->toString() === 'free') {
            return (string) __('capell-marketplace::marketplace.install.free');
        }

        return (string) Number::currency($extension->priceCents / 100, $extension->currency);
    }

    /** @param array<string, mixed> $trial */
    private function trialLabel(array $trial): ?string
    {
        $label = $trial['label'] ?? null;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        $days = $trial['days'] ?? $trial['duration_days'] ?? null;

        return is_numeric($days) && (int) $days > 0
            ? trans_choice('capell-marketplace::marketplace.suites.trial_days', (int) $days, [
                'count' => (int) $days,
            ])
            : null;
    }

    private function ratingAverageLabel(?float $ratingAverage): string
    {
        return $ratingAverage === null
            ? (string) __('capell-marketplace::marketplace.card.no_rating')
            : number_format($ratingAverage, 1);
    }

    /** @return list<string> */
    private function ratingStars(?float $ratingAverage): array
    {
        $roundedRating = $ratingAverage === null ? 0.0 : round($ratingAverage * 2) / 2;
        $stars = [];

        foreach (range(1, 5) as $starPosition) {
            if ($roundedRating >= $starPosition) {
                $stars[] = 'full';

                continue;
            }

            $stars[] = $roundedRating >= $starPosition - 0.5 ? 'half' : 'empty';
        }

        return $stars;
    }

    private function ratingsCountLabel(int $ratingsCount): string
    {
        return trans_choice('capell-marketplace::marketplace.card.ratings_count', $ratingsCount, [
            'count' => number_format($ratingsCount),
        ]);
    }

    /**
     * @param  list<string>  $categories
     * @return list<string>
     */
    private function categoryLabels(array $categories): array
    {
        return array_map(
            static fn (string $category): string => MarketplaceExtensionCategory::tryFrom($category)?->getLabel()
                ?? Str::headline($category),
            $categories,
        );
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return list<string>
     */
    private function capabilityLabels(array $capabilities): array
    {
        return array_map(
            static fn (string $capability): string => MarketplaceExtensionCapability::tryFrom($capability)?->getLabel()
                ?? Str::headline($capability),
            $this->capabilitySlugs($capabilities),
        );
    }

    /**
     * @param  list<string>  $states
     * @return list<string>
     */
    private function stateLabels(array $states): array
    {
        return array_map(
            static fn (string $state): string => Str::of($state)
                ->replace(['-', '_'], ' ')
                ->headline()
                ->toString(),
            $states,
        );
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return list<string>
     */
    private function capabilitySlugs(array $capabilities): array
    {
        $slugs = [];

        foreach ($capabilities as $capabilityKey => $capabilityValue) {
            if (is_string($capabilityKey)
                && $capabilityKey !== ''
                && $capabilityValue !== false
                && $capabilityValue !== null
            ) {
                $slugs[] = Str::snake($capabilityKey);

                continue;
            }

            if (is_array($capabilityValue)) {
                $capabilitySlug = $capabilityValue['slug'] ?? $capabilityValue['key'] ?? null;

                if (is_scalar($capabilitySlug) && (string) $capabilitySlug !== '') {
                    $slugs[] = Str::snake((string) $capabilitySlug);
                }

                continue;
            }

            if (is_scalar($capabilityValue) && (string) $capabilityValue !== '') {
                $slugs[] = Str::snake((string) $capabilityValue);
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  array<string, string>  $compatibilityDetails
     * @return list<string>
     */
    private function compatibilityWarnings(array $compatibilityDetails): array
    {
        $warnings = [];

        foreach ($compatibilityDetails as $platform => $status) {
            if ($status === 'incompatible') {
                $warnings[] = (string) __('capell-marketplace::marketplace.card.incompatible_platform', [
                    'platform' => (string) __('capell-marketplace::marketplace.platform-builder.' . $platform),
                ]);
            }
        }

        return $warnings;
    }
}
