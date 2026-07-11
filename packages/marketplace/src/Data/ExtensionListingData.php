<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelData\Data;
use Throwable;

final class ExtensionListingData extends Data
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $composerName,
        public readonly string $kind,
        public readonly ?string $description,
        public readonly int $priceCents,
        public readonly bool $isPaid,
        public readonly ?string $forkRepoUrl,
        public readonly ?string $productId,
        public readonly ?string $latestVersion = null,
        public readonly ?CarbonImmutable $releasedAt = null,
        /** @var array<string, mixed> */
        public readonly array $capabilities = [],
        public readonly ?string $capellVersionConstraint = null,
        public readonly ?string $laravelVersionConstraint = null,
        public readonly ?string $filamentVersionConstraint = null,
        public readonly ?string $documentationUrl = null,
        public readonly bool $requiresConfirmation = false,
        /** @var array<string, mixed> */
        public readonly array $installConfirmation = [],
        /** @var array<int, array<string, mixed>> */
        public readonly array $installOptions = [],
        public readonly bool $isFeatured = false,
        public readonly ?int $featuredRank = null,
        public readonly ?string $purchaseUrl = null,
        public readonly ?string $imageUrl = null,
        /** @var array<int, string> */
        public readonly array $imageUrls = [],
        public readonly ?string $livewireVersionConstraint = null,
        /** @var array<int, string> */
        public readonly array $categories = [],
        public readonly bool $publisherVerified = false,
        public readonly bool $securityReviewed = false,
        public readonly ?string $productGroup = null,
        public readonly bool $activationRequired = false,
        public readonly bool $installAuthorized = false,
        public readonly ?string $installState = null,
        public readonly ?string $primaryAction = null,
        public readonly ?string $authorName = null,
        public readonly ?string $authorSlug = null,
        public readonly ?float $ratingAverage = null,
        public readonly int $ratingsCount = 0,
        public readonly ?string $displayName = null,
        public readonly ?string $productTier = null,
        public readonly ?string $productBundle = null,
        public readonly ?string $effectiveCertification = null,
        public readonly ?string $supportPolicy = null,
        public readonly bool $privateDocsEntitled = false,
        /** @var array<string, mixed> */
        public readonly array $performanceBudget = [],
        /** @var array<string, int> */
        public readonly array $contributionSummary = [],
        public readonly ?string $installEligibility = null,
        public readonly ?string $blockedReason = null,
        public readonly ?string $nextAction = null,
        /** @var array<int, string> */
        public readonly array $surfaces = [],
        /** @var array<int, string> */
        public readonly array $requiredDependencies = [],
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
        public readonly ?MarketplaceInstallEligibilityData $installEligibilityPolicy = null,
        public readonly string $catalogueRole = 'extension',
        public readonly string $maturity = 'labs',
        public readonly string $maturityLabel = 'Labs',
        public readonly bool $includedWithCapellAll = false,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromApiResponse(array $item): self
    {
        $protectedInstall = self::protectedInstall($item);
        $catalogueReleaseMetadata = ExtensionCatalogueMetadataData::fromApiResponse($item);

        return new self(
            slug: (string) ($item['slug'] ?? ''),
            name: (string) ($item['name'] ?? ''),
            composerName: self::localPackageComposerName(self::nonEmptyString($item['composer_name'] ?? null)) ?? '',
            kind: (string) ($item['kind'] ?? 'tool'),
            description: $item['description'] ?? null,
            priceCents: (int) ($item['price_cents'] ?? 0),
            isPaid: (bool) ($item['is_paid'] ?? false),
            forkRepoUrl: $item['fork_repo_url'] ?? null,
            productId: $item['product_id'] ?? null,
            latestVersion: isset($item['latest_version']) && is_scalar($item['latest_version']) ? (string) $item['latest_version'] : null,
            releasedAt: self::parseReleasedAt($item['released_at'] ?? null),
            capabilities: $item['capabilities'] ?? [],
            capellVersionConstraint: $item['capell_version_constraint'] ?? null,
            laravelVersionConstraint: $item['laravel_version_constraint'] ?? null,
            filamentVersionConstraint: $item['filament_version_constraint'] ?? null,
            documentationUrl: $item['documentation_url'] ?? null,
            requiresConfirmation: (bool) ($item['requires_confirmation'] ?? false),
            installConfirmation: is_array($item['install_confirmation'] ?? null) ? $item['install_confirmation'] : [],
            installOptions: is_array($item['install_options'] ?? null) ? $item['install_options'] : [],
            isFeatured: (bool) ($item['is_featured'] ?? false),
            featuredRank: isset($item['featured_rank']) && is_numeric($item['featured_rank'])
                ? (int) $item['featured_rank']
                : null,
            purchaseUrl: self::nonEmptyString($item['purchase_url'] ?? $item['checkout_url'] ?? null),
            imageUrl: self::listingImageUrl($item),
            imageUrls: self::listingImageUrls($item),
            livewireVersionConstraint: $item['livewire_version_constraint'] ?? null,
            categories: self::stringList($item['categories'] ?? $item['category_slugs'] ?? []),
            publisherVerified: (bool) ($item['publisher_verified'] ?? $item['is_publisher_verified'] ?? false),
            securityReviewed: (bool) ($item['security_reviewed'] ?? $item['is_security_reviewed'] ?? false),
            productGroup: self::nonEmptyString($item['product_group'] ?? $item['productGroup'] ?? data_get($item, 'product.group')),
            activationRequired: (bool) ($item['activation_required'] ?? $item['requires_activation'] ?? false),
            installAuthorized: (bool) ($item['install_authorized'] ?? $item['is_authorized'] ?? false),
            installState: self::nonEmptyString($item['install_state'] ?? $item['marketplace_install_state'] ?? null),
            primaryAction: self::nonEmptyString($item['primary_action'] ?? null),
            authorName: self::authorName($item),
            authorSlug: self::nonEmptyString($item['author_slug'] ?? $item['publisher_slug'] ?? null),
            ratingAverage: self::ratingAverage($item),
            ratingsCount: self::ratingsCount($item),
            displayName: self::nonEmptyString($item['display_name'] ?? $item['displayName'] ?? $item['name'] ?? null),
            productTier: self::nonEmptyString(data_get($item, 'product.tier', $item['product_tier'] ?? null)),
            productBundle: self::nonEmptyString(data_get($item, 'product.bundle', $item['product_bundle'] ?? null)),
            effectiveCertification: self::nonEmptyString($item['effective_certification'] ?? data_get($item, 'commercial.requestedCertification', data_get($item, 'commercial.requested_certification', $item['certification'] ?? null))),
            supportPolicy: self::nonEmptyString($item['support_policy'] ?? data_get($item, 'commercial.supportPolicy', data_get($item, 'commercial.support_policy'))),
            privateDocsEntitled: (bool) ($item['private_docs_entitled'] ?? data_get($item, 'commercial.privateDocsEntitled', false)),
            performanceBudget: self::arrayValue($item['performance'] ?? $item['performance_budget'] ?? []),
            contributionSummary: self::integerMap($item['contribution_summary'] ?? $item['contributions_summary'] ?? []),
            installEligibility: self::nonEmptyString($item['install_eligibility'] ?? data_get($item, 'eligibility.state')),
            blockedReason: self::nonEmptyString($item['blocked_reason'] ?? null),
            nextAction: self::nonEmptyString($item['next_action'] ?? $item['primary_action'] ?? null),
            surfaces: self::stringList($item['surfaces'] ?? []),
            requiredDependencies: self::normalizedComposerNameList(data_get($item, 'dependencies.requires', [])),
            metadata: self::metadata($item),
            installEligibilityPolicy: MarketplaceInstallEligibilityData::fromPayload(
                $item['install_eligibility'] ?? $item['eligibility'] ?? null,
                protectedInstall: $protectedInstall,
            ),
            catalogueRole: $catalogueReleaseMetadata->catalogueRole,
            maturity: $catalogueReleaseMetadata->maturity,
            maturityLabel: $catalogueReleaseMetadata->maturityLabel,
            includedWithCapellAll: $catalogueReleaseMetadata->includedWithCapellAll,
        );
    }

    public static function localPackageComposerName(?string $composerName): ?string
    {
        if ($composerName === null) {
            return null;
        }

        if (array_key_exists($composerName, self::localPackageComposerNameAliases())) {
            return self::localPackageComposerNameAliases()[$composerName];
        }

        if (str_starts_with($composerName, 'capell-theme/')) {
            $themeSlug = str($composerName)
                ->after('capell-theme/')
                ->trim('/')
                ->toString();

            if ($themeSlug === 'foundation') {
                return 'capell-app/foundation-theme';
            }

            if ($themeSlug !== '') {
                return 'capell-app/theme-' . $themeSlug;
            }
        }

        return $composerName;
    }

    /** @return array<int, string> */
    public static function localPackageComposerNameCandidates(string $composerName): array
    {
        $normalizedComposerName = self::localPackageComposerName($composerName) ?? $composerName;
        $candidates = [$normalizedComposerName];

        foreach (self::localPackageComposerNameAliases() as $staleComposerName => $localComposerName) {
            if ($localComposerName === $normalizedComposerName) {
                $candidates[] = $staleComposerName;
            }
        }

        if ($normalizedComposerName === 'capell-app/foundation-theme') {
            $candidates[] = 'capell-theme/foundation';
        } elseif (str_starts_with($normalizedComposerName, 'capell-app/theme-')) {
            $themeSlug = str($normalizedComposerName)
                ->after('capell-app/theme-')
                ->trim('/')
                ->toString();

            if ($themeSlug !== '') {
                $candidates[] = 'capell-theme/' . $themeSlug;
            }
        }

        return array_values(array_unique($candidates));
    }

    /** @param array<string, mixed> $item */
    private static function protectedInstall(array $item): bool
    {
        if ((bool) ($item['is_paid'] ?? false)
            || (bool) ($item['activation_required'] ?? $item['requires_activation'] ?? false)) {
            return true;
        }

        $eligibility = MarketplaceInstallEligibilityData::fromPayload(
            $item['install_eligibility'] ?? $item['eligibility'] ?? null,
        );
        if ($eligibility->blocksInstall()) {
            return true;
        }

        if ($eligibility->state === MarketplaceInstallState::PurchaseRequired) {
            return true;
        }

        return $eligibility->state === MarketplaceInstallState::ActivationRequired;
    }

    private static function parseReleasedAt(mixed $releasedAt): ?CarbonImmutable
    {
        if (! is_string($releasedAt) || $releasedAt === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($releasedAt);
        } catch (Throwable) {
            return null;
        }
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /** @param array<string, mixed> $item */
    private static function listingImageUrl(array $item): ?string
    {
        return self::listingImageUrls($item)[0] ?? null;
    }

    /** @param array<string, mixed> $item */
    private static function listingImageUrls(array $item): array
    {
        $imageUrls = collect([
            self::localPackageImageUrl($item),
            self::nonEmptyString($item['logo_url'] ?? null),
            self::nonEmptyString($item['icon_url'] ?? null),
            self::nonEmptyString($item['card_image_url'] ?? $item['cardImageUrl'] ?? null),
            self::nonEmptyString($item['preview_image_url'] ?? $item['previewImageUrl'] ?? null),
            self::nonEmptyString($item['cover_image_url'] ?? $item['coverImageUrl'] ?? null),
            self::nonEmptyString($item['image_url'] ?? null),
        ])->filter();

        $screenshots = is_array($item['screenshots'] ?? null)
            ? $item['screenshots']
            : (is_array(data_get($item, 'marketplace.screenshots')) ? data_get($item, 'marketplace.screenshots') : []);

        $screenshotUrls = collect($screenshots)
            ->filter(fn (mixed $screenshot): bool => is_array($screenshot))
            ->map(fn (array $screenshot): ?string => self::nonEmptyString($screenshot['url'] ?? $screenshot['path'] ?? null))
            ->filter();

        return $imageUrls
            ->concat($screenshotUrls)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $item */
    private static function localPackageImageUrl(array $item): ?string
    {
        if (! Route::has('capell-admin.extension-asset')) {
            return null;
        }

        $composerName = self::localPackageComposerName(self::nonEmptyString($item['composer_name'] ?? null));

        if ($composerName === null || ! str_starts_with($composerName, 'capell-app/')) {
            return null;
        }

        $registry = resolve(CapellPackageRegistry::class);
        $manifest = $registry->all()[$composerName] ?? null;

        if ($manifest?->installPath === null) {
            return null;
        }

        $packagePath = realpath($manifest->installPath);
        $assetPath = realpath($manifest->installPath . DIRECTORY_SEPARATOR . 'docs/assets/marketplace/extension-card.jpg');

        if (
            ! is_string($packagePath)
            || ! is_string($assetPath)
            || ! str_starts_with($assetPath, $packagePath . DIRECTORY_SEPARATOR)
            || ! is_file($assetPath)
        ) {
            return null;
        }

        return route('capell-admin.extension-asset', [
            'package' => $composerName,
            'path' => 'docs/assets/marketplace/extension-card.jpg',
        ]);
    }

    /** @return array<string, string> */
    private static function localPackageComposerNameAliases(): array
    {
        return [
            'capell-app/analytics' => 'capell-app/dashboard-reports',
            'capell-app/authentication-log' => 'capell-app/login-audit',
            'capell-app/campaigns' => 'capell-app/campaign-studio',
            'capell-app/forms' => 'capell-app/form-builder',
            'capell-app/media-assistant' => 'capell-app/media-ai',
            'capell-app/migrator' => 'capell-app/migration-assistant',
            'capell-app/mosaic' => 'capell-app/layout-builder',
            'capell-app/workspaces' => 'capell-app/publishing-studio',
        ];
    }

    /** @return array<int, string> */
    private static function normalizedComposerNameList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            self::localPackageComposerName(...),
            self::stringList($value),
        )));
    }

    /** @return array<string, mixed> */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array<string, int> */
    private static function integerMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $count, mixed $key): bool => is_string($key) && is_numeric($count))
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return array<int, string> */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => is_scalar($value) && (string) $value !== '' ? (string) $value : null,
            $values,
        ), is_string(...)));
    }

    /** @param array<string, mixed> $item */
    private static function authorName(array $item): ?string
    {
        $authorName = self::nonEmptyString($item['author'] ?? $item['author_name'] ?? $item['publisher_name'] ?? null);

        if ($authorName !== null) {
            return $authorName;
        }

        $composerName = self::nonEmptyString($item['composer_name'] ?? null);

        return $composerName !== null && str_starts_with($composerName, 'capell-app/')
            ? 'Capell'
            : null;
    }

    /** @param array<string, mixed> $item */
    private static function ratingAverage(array $item): ?float
    {
        $summary = is_array($item['ratings_summary'] ?? null) ? $item['ratings_summary'] : [];
        $rating = $item['rating_average'] ?? $item['average_rating'] ?? $summary['average'] ?? null;

        if (! is_numeric($rating)) {
            return null;
        }

        return min(5.0, max(0.0, round((float) $rating, 1)));
    }

    /** @param array<string, mixed> $item */
    private static function ratingsCount(array $item): int
    {
        $summary = is_array($item['ratings_summary'] ?? null) ? $item['ratings_summary'] : [];
        $count = $item['ratings_count'] ?? $item['rating_count'] ?? $summary['count'] ?? null;

        return is_numeric($count) && (int) $count > 0 ? (int) $count : 0;
    }

    /** @param array<string, mixed> $item */
    private static function metadata(array $item): array
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $known = [
            'slug', 'name', 'display_name', 'displayName', 'composer_name', 'kind', 'description',
            'price_cents', 'is_paid', 'fork_repo_url', 'product_id', 'latest_version', 'released_at',
            'capabilities', 'capell_version_constraint', 'laravel_version_constraint',
            'filament_version_constraint', 'livewire_version_constraint', 'documentation_url',
            'requires_confirmation', 'install_confirmation', 'install_options', 'is_featured',
            'featured_rank', 'purchase_url', 'checkout_url', 'image_url', 'logo_url', 'icon_url',
            'card_image_url', 'cardImageUrl', 'preview_image_url', 'previewImageUrl',
            'cover_image_url', 'coverImageUrl',
            'categories', 'category_slugs', 'publisher_verified', 'is_publisher_verified',
            'security_reviewed', 'is_security_reviewed', 'product_group', 'productGroup',
            'activation_required', 'requires_activation', 'install_authorized', 'is_authorized',
            'install_state', 'marketplace_install_state', 'primary_action', 'author', 'author_name',
            'publisher_name', 'author_slug', 'publisher_slug', 'rating_average', 'average_rating',
            'ratings_summary', 'ratings_count', 'rating_count', 'product', 'commercial',
            'effective_certification', 'certification', 'support_policy', 'private_docs_entitled',
            'performance', 'performance_budget', 'contribution_summary', 'contributions_summary',
            'install_eligibility', 'eligibility', 'blocked_reason', 'next_action', 'surfaces', 'dependencies',
            'catalogue_role', 'maturity', 'maturity_label', 'included_with_capell_all',
            'metadata',
        ];

        return [
            ...$metadata,
            ...array_diff_key($item, array_flip($known)),
        ];
    }
}
