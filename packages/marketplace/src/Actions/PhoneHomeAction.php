<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Data\InstalledPackageData;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplacePayloadSigner;
use Capell\Marketplace\Support\MarketplaceWebhookUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class PhoneHomeAction
{
    use AsAction;

    private static ?string $lastFailureMessage = null;

    private ?string $failureMessage = null;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplacePayloadSigner $signer,
    ) {}

    public static function lastFailureMessage(): ?string
    {
        return self::$lastFailureMessage;
    }

    public function handle(): bool
    {
        $this->failureMessage = null;
        self::$lastFailureMessage = null;

        $baseUrl = config('capell-marketplace.marketplace.base_url');

        if ($baseUrl === null || $baseUrl === '') {
            Log::warning('capell-marketplace: marketplace.base_url not configured, skipping phone home.');
            $this->failureMessage = 'The marketplace URL is not configured. Set CAPELL_MARKETPLACE_URL to the Capell marketplace API URL.';
            self::$lastFailureMessage = $this->failureMessage;

            return false;
        }

        $webhookUrl = MarketplaceWebhookUrl::resolve();

        if ($webhookUrl === null) {
            Log::warning('capell-marketplace: marketplace webhook URL is not configured, skipping phone home.');
            $this->failureMessage = 'The marketplace webhook URL could not be resolved. Set APP_URL to this site URL or set CAPELL_MARKETPLACE_WEBHOOK_URL before running heartbeat.';
            self::$lastFailureMessage = $this->failureMessage;

            return false;
        }

        $marketplaceInstance = MarketplaceInstance::query()
            ->latest('last_heartbeat_at')
            ->first();

        $instanceId = $marketplaceInstance?->instance_id ?? config('capell-marketplace.instance.id');

        if (! is_string($instanceId) || $instanceId === '') {
            $this->failureMessage = 'This installation is not connected to Capell Marketplace. Connect a Capell account before running heartbeat.';
            self::$lastFailureMessage = $this->failureMessage;

            return false;
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
                    $this->failureMessage = 'The marketplace response did not include a signing secret for initial heartbeat bootstrap.';
                    self::$lastFailureMessage = $this->failureMessage;

                    return false;
                }

                MarketplaceInstance::query()->updateOrCreate(
                    ['instance_id' => $heartbeatResult->instanceId],
                    [
                        'signing_secret_encrypted' => $signingSecret,
                        'last_heartbeat_at' => now(),
                    ],
                );

                RecordUpdateAdvisorySnapshotAction::run(
                    source: 'heartbeat',
                    payload: $heartbeatResult->toArray(),
                );

                return true;
            }

            $this->failureMessage = 'The marketplace response did not confirm the connected instance ID.'
                . ' Heartbeat URL: ' . $heartbeatUrl . '.';
        } catch (ConnectionException $connectionException) {
            Log::warning('capell-marketplace: phone home connection failed', ['error' => $connectionException->getMessage()]);
            $this->failureMessage = 'Capell could not connect to the marketplace API. Heartbeat URL: '
                . $heartbeatUrl
                . '. Error: '
                . $connectionException->getMessage();
        } catch (RuntimeException $runtimeException) {
            Log::warning('capell-marketplace: phone home failed', ['error' => $runtimeException->getMessage()]);
            $this->failureMessage = $runtimeException->getMessage();
        }

        self::$lastFailureMessage = $this->failureMessage;

        return false;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }
}
