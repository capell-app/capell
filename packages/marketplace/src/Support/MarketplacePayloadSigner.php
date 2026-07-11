<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Marketplace\MarketplacePayloadSigner as CoreMarketplacePayloadSigner;

final class MarketplacePayloadSigner
{
    public function __construct(
        private readonly ?CoreMarketplacePayloadSigner $signer = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function signedPayload(array $payload, string $secret): array
    {
        return $this->signer()->signedPayload($payload, $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function signature(array $payload, string $secret): string
    {
        return $this->signer()->signature($payload, $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function rawSignature(array $payload, string $secret): string
    {
        return $this->signer()->rawSignature($payload, $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload, string $secret, ?string $signature = null): bool
    {
        return $this->signer()->verify($payload, $secret, $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalJson(array $payload): string
    {
        return $this->signer()->canonicalJson($payload);
    }

    private function signer(): CoreMarketplacePayloadSigner
    {
        return $this->signer ?? resolve(CoreMarketplacePayloadSigner::class);
    }
}
