<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class ResolveExtensionLicenceDecisionAction
{
    use AsFake;
    use AsObject;

    public function handle(string $slug, string $action, string $domain): ExtensionLicenceDecisionData
    {
        $client = $this->marketplaceClient();

        if ($client !== null) {
            $callback = [$client, 'extensionLicenceDecision'];

            throw_unless(is_callable($callback), RuntimeException::class, 'Marketplace client cannot resolve licence decisions.');

            $decision = $callback($slug, $action, $domain);

            throw_unless($decision instanceof ExtensionLicenceDecisionData, RuntimeException::class, 'Marketplace client returned an invalid licence decision.');

            return $decision;
        }

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

    private function licenceDecisionUrl(string $slug): string
    {
        $baseUrl = config('capell-marketplace.marketplace.base_url');

        throw_if(! is_string($baseUrl) || $baseUrl === '', RuntimeException::class, 'The marketplace URL is not configured.');

        return rtrim($baseUrl, '/') . '/extensions/' . $slug . '/licence-decision';
    }

    private function marketplaceClient(): ?object
    {
        if (! $this->hasSigningCredentials() || ! app()->bound('capell.marketplace.client')) {
            return null;
        }

        $client = resolve('capell.marketplace.client');

        if (! is_object($client) || ! method_exists($client, 'extensionLicenceDecision')) {
            return null;
        }

        return $client;
    }

    private function hasSigningCredentials(): bool
    {
        $configuredInstanceId = config('capell-marketplace.instance.id');
        $configuredSecret = config('capell-marketplace.marketplace.webhook_secret');

        return is_string($configuredInstanceId)
            && $configuredInstanceId !== ''
            && is_string($configuredSecret)
            && $configuredSecret !== '';
    }
}
