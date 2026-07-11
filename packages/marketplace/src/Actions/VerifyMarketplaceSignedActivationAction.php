<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplacePayloadSigner;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class VerifyMarketplaceSignedActivationAction
{
    use AsAction;

    public function __construct(private readonly MarketplacePayloadSigner $signer) {}

    /**
     * @param  array<string, mixed>  $activation
     */
    public function handle(CapellExtension $extension, array $activation): bool
    {
        if (($activation['composer_name'] ?? null) !== $extension->composer_name) {
            return false;
        }

        $signingSecret = $this->signingSecret($activation);

        if ($signingSecret === null) {
            return false;
        }

        $signature = $activation['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals($this->signer->signature($activation, $signingSecret), $signature);
    }

    /**
     * @param  array<string, mixed>  $activation
     */
    private function signingSecret(array $activation): ?string
    {
        $instanceId = $activation['instance_id'] ?? null;

        if (is_string($instanceId) && $instanceId !== '') {
            return $this->signingSecretForInstance($instanceId);
        }

        $signingSecret = config('capell-marketplace.marketplace.webhook_secret');

        return is_string($signingSecret) && $signingSecret !== '' ? $signingSecret : null;
    }

    private function signingSecretForInstance(string $instanceId): ?string
    {
        $marketplaceInstance = null;

        try {
            $marketplaceInstance = MarketplaceInstance::query()
                ->where('instance_id', $instanceId)
                ->first();
        } catch (Throwable) {
            $marketplaceInstance = null;
        }

        $signingSecret = $marketplaceInstance?->signing_secret_encrypted;

        return is_string($signingSecret) && $signingSecret !== '' ? $signingSecret : null;
    }
}
