<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Models\CapellExtension;
use Illuminate\Support\Facades\Http;
use Override;
use RuntimeException;

/**
 * Used when the marketplace package is absent or disabled. It has no instance
 * credentials, so licence decisions go out unsigned and no installed receipt
 * can be verified.
 */
final class NullExtensionEntitlements implements ExtensionEntitlements
{
    #[Override]
    public function licenceDecision(string $slug, string $action, string $domain): ExtensionLicenceDecisionData
    {
        $response = Http::timeout(config('capell-marketplace.marketplace.timeout_seconds', 10))
            ->acceptJson()
            ->post($this->licenceDecisionUrl($slug), [
                'action' => $action,
                'domain' => $domain,
                'app_url' => config('app.url'),
            ]);

        throw_unless($response->successful(), RuntimeException::class, 'Marketplace could not resolve this licence decision.');

        $data = $response->json('data');

        throw_unless(is_array($data), RuntimeException::class, 'The marketplace licence decision response did not include a data object.');

        return ExtensionLicenceDecisionData::fromApiResponse($data);
    }

    /**
     * @param  array<string, mixed>  $installedReceipt
     */
    #[Override]
    public function verifyActivation(CapellExtension $extension, array $installedReceipt): bool
    {
        return false;
    }

    private function licenceDecisionUrl(string $slug): string
    {
        $baseUrl = config('capell-marketplace.marketplace.base_url');

        throw_if(! is_string($baseUrl) || $baseUrl === '', RuntimeException::class, 'The marketplace URL is not configured.');

        return rtrim($baseUrl, '/') . '/extensions/' . $slug . '/licence-decision';
    }
}
