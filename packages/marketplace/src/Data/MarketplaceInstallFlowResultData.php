<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceInstallFlowResultData extends Data
{
    /**
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $instance
     * @param  array<string, mixed>  $quote
     * @param  array<string, int>  $entitlements
     * @param  array<int, array<string, mixed>>  $eligibility
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $flowId,
        public readonly int $contractVersion,
        public readonly array $account,
        public readonly array $instance,
        public readonly array $quote,
        public readonly array $entitlements,
        public readonly array $eligibility,
        public readonly bool $canInstall,
        public readonly ?string $blockReason = null,
        public readonly array $metadata = [],
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiResponse(array $payload): self
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return new self(
            flowId: (string) ($data['flow_id'] ?? ''),
            contractVersion: (int) ($data['contract_version'] ?? 1),
            account: is_array($data['account'] ?? null) ? $data['account'] : [],
            instance: is_array($data['instance'] ?? null) ? $data['instance'] : [],
            quote: is_array($data['quote'] ?? null) ? $data['quote'] : [],
            entitlements: collect(is_array($data['entitlements'] ?? null) ? $data['entitlements'] : [])
                ->filter(fn (mixed $entitlementId): bool => is_numeric($entitlementId))
                ->map(fn (mixed $entitlementId): int => (int) $entitlementId)
                ->all(),
            eligibility: array_values(array_filter(
                is_array($data['eligibility'] ?? null) ? $data['eligibility'] : [],
                is_array(...),
            )),
            canInstall: (bool) ($data['can_install'] ?? false),
            blockReason: is_string($data['block_reason'] ?? null) ? $data['block_reason'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            payload: $data,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function quoteExtensions(): array
    {
        return array_values(array_filter(
            is_array($this->quote['extensions'] ?? null) ? $this->quote['extensions'] : [],
            is_array(...),
        ));
    }

    public function quotePriceCents(): int
    {
        return (int) ($this->quote['price_cents'] ?? 0);
    }

    public function quoteCurrency(): ?string
    {
        return is_string($this->quote['currency'] ?? null) ? $this->quote['currency'] : null;
    }
}
