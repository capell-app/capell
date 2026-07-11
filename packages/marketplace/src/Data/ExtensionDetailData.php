<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Spatie\LaravelData\Data;

final class ExtensionDetailData extends Data
{
    /**
     * @param  array<int, string>  $categories
     * @param  array<int, array<string, mixed>>  $images
     * @param  array<int, array<string, mixed>>  $documentation
     * @param  array<int, array<string, mixed>>  $versionHistory
     * @param  array<string, mixed>  $ratingsSummary
     * @param  array<string, mixed>  $commentsSummary
     * @param  array<string, mixed>  $tipsSummary
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $composerName,
        public readonly string $kind,
        public readonly ?string $description,
        public readonly ?string $summary,
        public readonly int $priceCents,
        public readonly bool $isPaid,
        public readonly ?string $productId,
        public readonly ?string $latestVersion,
        public readonly ?string $documentationUrl,
        public readonly ?string $purchaseUrl,
        public readonly ?string $imageUrl,
        public readonly ?string $publisherName,
        public readonly bool $publisherVerified,
        public readonly bool $securityReviewed,
        public readonly array $categories = [],
        public readonly array $images = [],
        public readonly array $documentation = [],
        public readonly array $versionHistory = [],
        public readonly array $ratingsSummary = [],
        public readonly array $commentsSummary = [],
        public readonly array $tipsSummary = [],
        public readonly array $capabilities = [],
        public readonly array $metadata = [],
        public readonly ?ExtensionLicenceDecisionData $licence = null,
        public readonly ?string $displayName = null,
        public readonly ?string $productGroup = null,
        public readonly ?string $productTier = null,
        public readonly ?string $productBundle = null,
        public readonly ?string $effectiveCertification = null,
        public readonly ?string $supportPolicy = null,
        public readonly bool $privateDocsEntitled = false,
        /** @var array<int, string> */
        public readonly array $surfaces = [],
        /** @var array<int, string> */
        public readonly array $requiredDependencies = [],
        /** @var array<string, mixed> */
        public readonly array $performanceBudget = [],
        /** @var array<string, int> */
        public readonly array $contributionSummary = [],
        public readonly ?string $installEligibility = null,
        public readonly ?string $blockedReason = null,
        public readonly ?string $nextAction = null,
        public readonly ?string $healthStatus = null,
        public readonly ?MarketplaceInstallEligibilityData $installEligibilityPolicy = null,
        public readonly string $catalogueRole = 'extension',
        public readonly string $maturity = 'labs',
        public readonly string $maturityLabel = 'Labs',
        public readonly bool $includedWithCapellAll = false,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiResponse(array $payload): self
    {
        $catalogueReleaseMetadata = ExtensionCatalogueMetadataData::fromApiResponse($payload);

        return new self(
            slug: self::stringValue($payload['slug'] ?? ''),
            name: self::stringValue($payload['name'] ?? ''),
            composerName: self::stringValue($payload['composer_name'] ?? ''),
            kind: self::stringValue($payload['kind'] ?? 'tool'),
            description: self::optionalString($payload['description'] ?? null),
            summary: self::optionalString($payload['summary'] ?? null),
            priceCents: (int) ($payload['price_cents'] ?? 0),
            isPaid: (bool) ($payload['is_paid'] ?? false),
            productId: self::optionalString($payload['product_id'] ?? null),
            latestVersion: self::optionalString($payload['latest_version'] ?? null),
            documentationUrl: self::optionalString($payload['documentation_url'] ?? null),
            purchaseUrl: self::optionalString($payload['purchase_url'] ?? $payload['checkout_url'] ?? null),
            imageUrl: self::marketplaceAssetUrl(self::optionalString($payload['image_url'] ?? $payload['logo_url'] ?? $payload['icon_url'] ?? null)),
            publisherName: self::optionalString($payload['publisher_name'] ?? null),
            publisherVerified: (bool) ($payload['publisher_verified'] ?? $payload['is_publisher_verified'] ?? false),
            securityReviewed: (bool) ($payload['security_reviewed'] ?? $payload['is_security_reviewed'] ?? false),
            categories: self::stringList($payload['categories'] ?? $payload['category_slugs'] ?? []),
            images: self::imageList($payload['images'] ?? []),
            documentation: self::listOfArrays($payload['documentation'] ?? $payload['docs'] ?? []),
            versionHistory: self::listOfArrays($payload['version_history'] ?? $payload['versions'] ?? []),
            ratingsSummary: is_array($payload['ratings_summary'] ?? null) ? $payload['ratings_summary'] : [],
            commentsSummary: is_array($payload['comments_summary'] ?? null) ? $payload['comments_summary'] : [],
            tipsSummary: is_array($payload['tips_summary'] ?? null) ? $payload['tips_summary'] : [],
            capabilities: is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [],
            metadata: self::metadata($payload),
            licence: is_array($payload['licence'] ?? null) ? ExtensionLicenceDecisionData::fromApiResponse($payload['licence']) : null,
            displayName: self::optionalString($payload['display_name'] ?? $payload['displayName'] ?? $payload['name'] ?? null),
            productGroup: self::optionalString($payload['product_group'] ?? $payload['productGroup'] ?? data_get($payload, 'product.group')),
            productTier: self::optionalString($payload['product_tier'] ?? data_get($payload, 'product.tier')),
            productBundle: self::optionalString($payload['product_bundle'] ?? data_get($payload, 'product.bundle')),
            effectiveCertification: self::optionalString($payload['effective_certification'] ?? data_get($payload, 'commercial.requestedCertification', data_get($payload, 'commercial.requested_certification', $payload['certification'] ?? null))),
            supportPolicy: self::optionalString($payload['support_policy'] ?? data_get($payload, 'commercial.supportPolicy', data_get($payload, 'commercial.support_policy'))),
            privateDocsEntitled: (bool) ($payload['private_docs_entitled'] ?? data_get($payload, 'commercial.privateDocsEntitled', false)),
            surfaces: self::stringList($payload['surfaces'] ?? []),
            requiredDependencies: self::stringList(data_get($payload, 'dependencies.requires', [])),
            performanceBudget: self::arrayValue($payload['performance'] ?? $payload['performance_budget'] ?? []),
            contributionSummary: self::integerMap($payload['contribution_summary'] ?? $payload['contributions_summary'] ?? []),
            installEligibility: self::optionalString($payload['install_eligibility'] ?? data_get($payload, 'eligibility.state')),
            blockedReason: self::optionalString($payload['blocked_reason'] ?? null),
            nextAction: self::optionalString($payload['next_action'] ?? null),
            healthStatus: self::optionalString($payload['health_status'] ?? $payload['healthState'] ?? null),
            installEligibilityPolicy: MarketplaceInstallEligibilityData::fromPayload(
                $payload['install_eligibility'] ?? $payload['eligibility'] ?? null,
                protectedInstall: (bool) ($payload['is_paid'] ?? false)
                    || (bool) ($payload['activation_required'] ?? $payload['requires_activation'] ?? false)
                    || (bool) ($payload['requires_confirmation'] ?? false),
            ),
            catalogueRole: $catalogueReleaseMetadata->catalogueRole,
            maturity: $catalogueReleaseMetadata->maturity,
            maturityLabel: $catalogueReleaseMetadata->maturityLabel,
            includedWithCapellAll: $catalogueReleaseMetadata->includedWithCapellAll,
        );
    }

    public function manualComposerRequireCommand(): string
    {
        $requirement = $this->latestVersion === null
            ? $this->composerName
            : $this->composerName . ':^' . $this->latestVersion;

        return 'composer require ' . $this->shellToken($requirement);
    }

    public function manualExtensionInstallCommand(): string
    {
        return 'php artisan capell:extension-install ' . $this->shellToken($this->composerName);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listOfArrays(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_array(...)));
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = (string) $value;

        return $stringValue !== '' ? $stringValue : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function imageList(mixed $values): array
    {
        return array_values(array_filter(array_map(
            function (array $image): ?array {
                $url = self::marketplaceAssetUrl(self::optionalString($image['url'] ?? null));

                if ($url === null) {
                    return null;
                }

                return [
                    ...$image,
                    'url' => $url,
                ];
            },
            self::listOfArrays($values),
        )));
    }

    private static function marketplaceAssetUrl(?string $url): ?string
    {
        return MarketplaceAssetUrl::toUrl($url);
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

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<int, string>
     */
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

    /** @param array<string, mixed> $payload */
    private static function metadata(array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $known = [
            'slug', 'name', 'display_name', 'displayName', 'composer_name', 'kind', 'description',
            'summary', 'price_cents', 'is_paid', 'product_id', 'latest_version',
            'documentation_url', 'purchase_url', 'checkout_url', 'image_url', 'logo_url',
            'icon_url', 'publisher_name', 'publisher_verified', 'is_publisher_verified',
            'security_reviewed', 'is_security_reviewed', 'categories', 'category_slugs',
            'images', 'documentation', 'docs', 'version_history', 'versions', 'ratings_summary',
            'comments_summary', 'tips_summary', 'capabilities', 'licence', 'product',
            'product_group', 'productGroup', 'product_tier', 'product_bundle', 'commercial',
            'effective_certification', 'certification', 'support_policy', 'private_docs_entitled',
            'surfaces', 'dependencies', 'performance', 'performance_budget', 'contribution_summary',
            'contributions_summary', 'install_eligibility', 'eligibility', 'blocked_reason', 'next_action',
            'health_status', 'healthState', 'catalogue_role', 'maturity', 'maturity_label',
            'included_with_capell_all', 'metadata',
        ];

        return [
            ...$metadata,
            ...array_diff_key($payload, array_flip($known)),
        ];
    }

    private function shellToken(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.\/:\^~*\-]+$/', $value) === 1
            ? $value
            : escapeshellarg($value);
    }
}
