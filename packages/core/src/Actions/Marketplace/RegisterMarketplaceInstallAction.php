<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Models\MarketplaceInstall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class RegisterMarketplaceInstallAction
{
    use AsAction;

    public function handle(?string $installId = null): MarketplaceInstall
    {
        $marketplaceInstall = $this->resolveMarketplaceInstall($installId);

        if (! $marketplaceInstall instanceof MarketplaceInstall) {
            $marketplaceInstall = new MarketplaceInstall([
                'install_id' => $installId ?? (string) Str::uuid(),
                'registered_at' => now(),
            ]);
        }

        if (! is_string($marketplaceInstall->public_key) || $marketplaceInstall->public_key === ''
            || ! is_string($marketplaceInstall->private_key_encrypted) || $marketplaceInstall->private_key_encrypted === '') {
            $keyPair = $this->generateKeyPair();
            $marketplaceInstall->public_key = $keyPair['public_key'];
            $marketplaceInstall->private_key_encrypted = $keyPair['private_key'];
        }

        $marketplaceInstall->site_url = $this->configuredSiteUrl();
        $marketplaceInstall->environment = $this->configuredEnvironment();
        $marketplaceInstall->registered_at ??= CarbonImmutable::now();
        $marketplaceInstall->save();

        return $marketplaceInstall->refresh();
    }

    private function resolveMarketplaceInstall(?string $installId): ?MarketplaceInstall
    {
        if (is_string($installId) && $installId !== '') {
            return MarketplaceInstall::query()
                ->where('install_id', $installId)
                ->first();
        }

        return MarketplaceInstall::query()->first();
    }

    /**
     * @return array{public_key: string, private_key: string}
     */
    private function generateKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        throw_if($key === false, RuntimeException::class, 'Unable to generate marketplace install key pair.');

        $exported = openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);

        throw_if($exported === false || ! is_string($privateKey) || ! is_array($details), RuntimeException::class, 'Unable to export marketplace install key pair.');

        $publicKey = $details['key'] ?? null;

        throw_if(! is_string($publicKey) || $publicKey === '', RuntimeException::class, 'Unable to export marketplace install public key.');

        return [
            'public_key' => $publicKey,
            'private_key' => $privateKey,
        ];
    }

    private function configuredSiteUrl(): ?string
    {
        $siteUrl = config('app.url');

        return is_string($siteUrl) && $siteUrl !== '' ? $siteUrl : null;
    }

    private function configuredEnvironment(): ?string
    {
        $environment = config('app.env');

        return is_string($environment) && $environment !== '' ? $environment : null;
    }
}
