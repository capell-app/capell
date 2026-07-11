<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Services\MarketplaceClient;
use Spatie\LaravelData\Data;

final class MarketplaceCatalogueQueryData extends Data
{
    /**
     * @param  array<int, string>  $capabilities
     * @param  array<int, string>  $installedComposerNames
     */
    public function __construct(
        public readonly string $search = '',
        public readonly string $kind = '',
        public readonly bool $freeOnly = false,
        public readonly string $sort = MarketplaceClient::DEFAULT_EXTENSION_SORT,
        public readonly ?int $priceMinCents = null,
        public readonly ?int $priceMaxCents = null,
        public readonly ?string $capellVersion = null,
        public readonly ?string $laravelVersion = null,
        public readonly ?string $livewireVersion = null,
        public readonly ?string $filamentVersion = null,
        public readonly ?string $category = null,
        public readonly array $capabilities = [],
        public readonly ?string $author = null,
        public readonly string $installedStatus = '',
        public readonly array $installedComposerNames = [],
        public readonly int $page = 1,
        public readonly int $perPage = 9,
        public readonly bool $includeMarketplaceContext = true,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            search: self::stringValue($payload['search'] ?? ''),
            kind: self::stringValue($payload['kind'] ?? ''),
            freeOnly: (bool) ($payload['free_only'] ?? false),
            sort: self::stringValue($payload['sort'] ?? MarketplaceClient::DEFAULT_EXTENSION_SORT),
            priceMinCents: self::nullableInt($payload['price_min_cents'] ?? null),
            priceMaxCents: self::nullableInt($payload['price_max_cents'] ?? null),
            capellVersion: self::nullableString($payload['capell_version'] ?? null),
            laravelVersion: self::nullableString($payload['laravel_version'] ?? null),
            livewireVersion: self::nullableString($payload['livewire_version'] ?? null),
            filamentVersion: self::nullableString($payload['filament_version'] ?? null),
            category: self::nullableString($payload['category'] ?? null),
            capabilities: self::stringList($payload['capabilities'] ?? []),
            author: self::nullableString($payload['author'] ?? null),
            installedStatus: self::stringValue($payload['installed_status'] ?? ''),
            installedComposerNames: self::stringList($payload['installed_composer_names'] ?? []),
            page: max(1, self::intValue($payload['page'] ?? 1)),
            perPage: max(1, self::intValue($payload['per_page'] ?? 9)),
            includeMarketplaceContext: (bool) ($payload['include_marketplace_context'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'search' => $this->search,
            'kind' => $this->kind,
            'free_only' => $this->freeOnly,
            'sort' => $this->sort,
            'price_min_cents' => $this->priceMinCents,
            'price_max_cents' => $this->priceMaxCents,
            'capell_version' => $this->capellVersion,
            'laravel_version' => $this->laravelVersion,
            'livewire_version' => $this->livewireVersion,
            'filament_version' => $this->filamentVersion,
            'category' => $this->category,
            'capabilities' => $this->normalizedList($this->capabilities),
            'author' => $this->author,
            'installed_status' => $this->installedStatus,
            'installed_composer_names' => $this->normalizedList($this->installedComposerNames),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'include_marketplace_context' => $this->includeMarketplaceContext,
        ];
    }

    /**
     * @param  array{instance_id?: string, account_id?: string}  $marketplaceContext
     * @return array<string, mixed>
     */
    public function toCachePayload(array $marketplaceContext): array
    {
        return [
            ...$this->toPayload(),
            'context' => $this->includeMarketplaceContext ? $marketplaceContext : [],
        ];
    }

    /**
     * @param  array{instance_id?: string, account_id?: string}  $marketplaceContext
     * @return array<string, string>
     */
    public function toRequestParameters(array $marketplaceContext): array
    {
        return array_filter(
            [
                'search' => $this->search,
                'kind' => $this->kind,
                'free' => $this->freeOnly ? '1' : '',
                'sort' => $this->sort,
                'min_price_cents' => $this->priceMinCents === null ? '' : (string) $this->priceMinCents,
                'max_price_cents' => $this->priceMaxCents === null ? '' : (string) $this->priceMaxCents,
                'capell_version' => $this->capellVersion ?? '',
                'laravel_version' => $this->laravelVersion ?? '',
                'livewire_version' => $this->livewireVersion ?? '',
                'filament_version' => $this->filamentVersion ?? '',
                'category' => $this->category ?? '',
                'capabilities' => implode(',', $this->requestList($this->capabilities)),
                'author' => $this->author ?? '',
                'installed_status' => $this->installedStatus,
                'installed_composer_names' => implode(',', $this->requestList($this->installedComposerNames)),
                'page' => (string) $this->page,
                'per_page' => (string) $this->perPage,
                'instance_id' => $this->includeMarketplaceContext ? $marketplaceContext['instance_id'] ?? '' : '',
                'account_id' => $this->includeMarketplaceContext ? $marketplaceContext['account_id'] ?? '' : '',
            ],
            fn (string $value): bool => $value !== '',
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function normalizedList(array $values): array
    {
        $normalized = array_values(array_unique(array_filter(
            $values,
            fn (string $value): bool => $value !== '',
        )));

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function requestList(array $values): array
    {
        return array_values(array_unique(array_filter(
            $values,
            fn (string $value): bool => $value !== '',
        )));
    }
}
