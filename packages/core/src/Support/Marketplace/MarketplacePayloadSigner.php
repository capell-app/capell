<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Illuminate\Support\Str;

final class MarketplacePayloadSigner
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function signedPayload(array $payload, string $secret): array
    {
        $payload['signature_algorithm'] = 'hmac-sha256';
        $payload['signature_nonce'] ??= (string) Str::uuid();
        $payload['signature_issued_at'] = now()->toIso8601String();
        $payload['signature'] = $this->signature($payload, $secret);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function signature(array $payload, string $secret): string
    {
        return 'sha256=' . $this->rawSignature($payload, $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function rawSignature(array $payload, string $secret): string
    {
        unset($payload['signature']);

        return hash_hmac('sha256', $this->canonicalJson($payload), $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload, string $secret, ?string $signature = null): bool
    {
        $signature ??= $payload['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals($this->signature($payload, $secret), $signature)
            || hash_equals($this->rawSignature($payload, $secret), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalJson(array $payload): string
    {
        return json_encode($this->sortRecursively($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->sortRecursively($nestedValue);
        }

        return $value;
    }
}
