<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Data\Marketplace\ExtensionHealthAlertData;
use Capell\Core\Models\MarketplaceInstall;
use Capell\Core\Support\Marketplace\MarketplacePayloadSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class SendExtensionHealthReportAction
{
    use AsAction;

    public function __construct(
        private readonly MarketplacePayloadSigner $signer,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     */
    public function handle(
        string $source,
        ?string $instanceId = null,
        ?string $signingSecret = null,
        ?string $webhookUrl = null,
    ): array {
        $marketplaceInstall = RegisterMarketplaceInstallAction::run($instanceId);
        $resolvedInstanceId = $instanceId ?? $marketplaceInstall->install_id;
        $payload = BuildExtensionHealthReportAction::run(
            source: $source,
            instanceId: $resolvedInstanceId,
            webhookUrl: $webhookUrl,
        );

        if (is_string($signingSecret) && $signingSecret !== '') {
            $payload = $this->signer->signedPayload($payload, $signingSecret);
        }

        $response = Http::timeout(config('capell-marketplace.marketplace.timeout_seconds', 10))
            ->acceptJson()
            ->post($this->heartbeatUrl(), $payload);

        if (! $response->successful()) {
            throw new RuntimeException('The marketplace rejected the heartbeat with HTTP status ' . $response->status() . '.');
        }

        $responseData = $response->json('data');

        throw_unless(is_array($responseData), RuntimeException::class, 'The marketplace response did not include the expected data payload.');

        RecordExtensionHealthAlertsAction::run(
            source: $source,
            alerts: $this->verifiedAlerts($responseData['alerts'] ?? [], $signingSecret),
        );

        $this->markReported($resolvedInstanceId);

        return $responseData;
    }

    private function heartbeatUrl(): string
    {
        $baseUrl = config('capell-marketplace.marketplace.base_url');

        throw_if(! is_string($baseUrl) || $baseUrl === '', RuntimeException::class, 'The marketplace URL is not configured.');

        return rtrim($baseUrl, '/') . '/instances/heartbeat';
    }

    /**
     * @return array<int, ExtensionHealthAlertData>
     */
    private function verifiedAlerts(mixed $alerts, ?string $signingSecret): array
    {
        if (! is_array($alerts) || ! is_string($signingSecret) || $signingSecret === '') {
            return [];
        }

        return collect($alerts)
            ->filter(fn (mixed $alert): bool => is_array($alert))
            ->filter(fn (array $alert): bool => $this->alertSignatureIsValid($alert, $signingSecret))
            ->map(fn (array $alert): ExtensionHealthAlertData => ExtensionHealthAlertData::fromApiResponse($alert))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    private function alertSignatureIsValid(array $alert, string $signingSecret): bool
    {
        $signature = $alert['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return $this->signer->verify($alert, $signingSecret, $signature);
    }

    private function markReported(string $instanceId): void
    {
        $marketplaceInstall = MarketplaceInstall::query()
            ->where('install_id', $instanceId)
            ->first();

        if (! $marketplaceInstall instanceof MarketplaceInstall) {
            $marketplaceInstall = MarketplaceInstall::query()->first();
        }

        $marketplaceInstall?->forceFill(['last_reported_at' => now()])->save();
    }
}
