<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Data\InstalledPackageData;
use Capell\Marketplace\Data\PhoneHomeResultData;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplacePayloadSigner;
use Capell\Marketplace\Support\MarketplaceWebhookUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class PhoneHomeAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplacePayloadSigner $signer,
    ) {}

    public function handle(): bool
    {
        return $this->result()->successful;
    }

    public function result(): PhoneHomeResultData
    {

        $baseUrl = config('capell-marketplace.marketplace.base_url');

        if ($baseUrl === null || $baseUrl === '') {
            Log::warning('capell-marketplace: marketplace.base_url not configured, skipping phone home.');

            return new PhoneHomeResultData(
                successful: false,
                failureMessage: 'The marketplace URL is not configured. Set CAPELL_MARKETPLACE_URL to the Capell marketplace API URL.',
            );
        }

        $webhookUrl = MarketplaceWebhookUrl::resolve();

        if ($webhookUrl === null) {
            Log::warning('capell-marketplace: marketplace webhook URL is not configured, skipping phone home.');

            return new PhoneHomeResultData(
                successful: false,
                failureMessage: 'The marketplace webhook URL could not be resolved. Set APP_URL to this site URL or set CAPELL_MARKETPLACE_WEBHOOK_URL before running heartbeat.',
            );
        }

        $marketplaceInstance = MarketplaceInstance::query()
            ->latest('last_heartbeat_at')
            ->first();

        $instanceId = $marketplaceInstance?->instance_id ?? config('capell-marketplace.instance.id');

        if (! is_string($instanceId) || $instanceId === '') {
            return new PhoneHomeResultData(
                successful: false,
                failureMessage: 'This installation is not connected to Capell Marketplace. Connect a Capell account before running heartbeat.',
            );
        }

        $capellVersion = CapellCore::getInstalledPrettyVersion('capell-app/capell')
            ?? CapellCore::getInstalledPrettyVersion('capell/core');
        $heartbeatUrl = $baseUrl . '/instances/heartbeat';

        try {
            $installed = array_map(
                fn (InstalledPackageData $package): array => $package->toArray(),
                BuildInstalledPackageSnapshotAction::run(),
            );

            $payload = [
                'event_type' => 'extension_health_report',
                'source' => 'heartbeat',
                'instance_id' => $instanceId,
                'webhook_url' => $webhookUrl,
                'app_url' => config('app.url'),
                'capell_version' => $capellVersion,
                'installed' => $installed,
            ];

            $signingSecret = $marketplaceInstance?->signing_secret_encrypted ?? config('capell-marketplace.marketplace.webhook_secret');
            if (is_string($signingSecret) && $signingSecret !== '') {
                $payload = $this->signer->signedPayload($payload, $signingSecret);
            }

            $heartbeatResult = $this->marketplace->heartbeat($payload);

            if ($marketplaceInstance === null || $heartbeatResult->instanceId === $marketplaceInstance->instance_id) {
                $signingSecret = $heartbeatResult->signingSecret ?? $marketplaceInstance?->signing_secret_encrypted;

                if (! is_string($signingSecret) || $signingSecret === '') {
                    return new PhoneHomeResultData(
                        successful: false,
                        failureMessage: 'The marketplace response did not include a signing secret for initial heartbeat bootstrap.',
                    );
                }

                MarketplaceInstance::query()->updateOrCreate(
                    ['instance_id' => $heartbeatResult->instanceId],
                    [
                        'signing_secret_encrypted' => $signingSecret,
                        'connection_metadata' => [
                            ...(is_array($marketplaceInstance?->connection_metadata) ? $marketplaceInstance->connection_metadata : []),
                            'commercial' => $heartbeatResult->commercial,
                        ],
                        'last_heartbeat_at' => now(),
                    ],
                );

                RecordUpdateAdvisorySnapshotAction::run(
                    source: 'heartbeat',
                    payload: $heartbeatResult->toArray(),
                );

                return new PhoneHomeResultData(successful: true);
            }

            $failureMessage = 'The marketplace response did not confirm the connected instance ID.'
                . ' Heartbeat URL: ' . $heartbeatUrl . '.';
        } catch (ConnectionException $connectionException) {
            Log::warning('capell-marketplace: phone home connection failed', ['error' => $connectionException->getMessage()]);
            $failureMessage = 'Capell could not connect to the marketplace API. Heartbeat URL: '
                . $heartbeatUrl
                . '. Error: '
                . $connectionException->getMessage();
        } catch (RuntimeException $runtimeException) {
            Log::warning('capell-marketplace: phone home failed', ['error' => $runtimeException->getMessage()]);
            $failureMessage = $runtimeException->getMessage();
        }

        return new PhoneHomeResultData(successful: false, failureMessage: $failureMessage);
    }
}
