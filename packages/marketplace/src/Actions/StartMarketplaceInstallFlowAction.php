<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\CreateMarketplaceInstallFlowSessionData;
use Capell\Marketplace\Data\InstalledPackageData;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceApprovalUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

final class StartMarketplaceInstallFlowAction
{
    use AsAction;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    public function handle(CreateMarketplaceInstallFlowSessionData $data): string
    {
        $state = Str::random(64);
        $codeVerifier = Str::random(96);

        $session = MarketplaceInstallFlowSession::query()->create([
            'status' => MarketplaceInstallFlowSessionStatus::Pending,
            'selected_extensions' => $data->selectedExtensions,
            'install_options' => $data->installOptions,
            'dependency_snapshot' => $data->dependencySnapshot,
            'user_context' => $data->userContext,
            'state_hash' => hash('sha256', $state),
            'code_verifier_hash' => hash('sha256', $codeVerifier),
            'code_verifier_encrypted' => $codeVerifier,
            'return_url' => $data->returnUrl,
            'expires_at' => now()->addMinutes(20),
        ]);

        try {
            $response = $this->marketplace->createInstallFlow([
                'state' => $state,
                'contract_version' => 2,
                'code_challenge' => hash('sha256', $codeVerifier),
                'return_url' => $data->returnUrl,
                'app_url' => rtrim((string) config('app.url'), '/'),
                'selected_extensions' => $data->selectedExtensions,
                'install_options' => $data->installOptions,
                'dependency_snapshot' => $data->dependencySnapshot,
                'user_context' => $data->userContext,
                'installed' => array_map(
                    fn (InstalledPackageData $package): array => $package->toArray(),
                    BuildInstalledPackageSnapshotAction::run(),
                ),
            ]);

            $flowId = $this->requiredString($response, 'flow_id');
            $approvalUrl = MarketplaceApprovalUrl::validate($this->requiredString($response, 'approval_url'));
        } catch (Throwable $throwable) {
            MarketplaceInstallFlowSessionTransitionAction::run($session, MarketplaceInstallFlowSessionStatus::Failed, $throwable->getMessage());

            throw $throwable;
        }

        $quote = is_array($response['quote'] ?? null) ? $response['quote'] : [];

        $session->forceFill([
            'remote_flow_id' => $flowId,
            'approval_url' => $approvalUrl,
            'contract_version' => (int) ($response['contract_version'] ?? 1),
            'quoted_extensions' => array_values(array_filter(is_array($quote['extensions'] ?? null) ? $quote['extensions'] : [], is_array(...))),
            'quoted_price_cents' => (int) ($quote['price_cents'] ?? 0),
            'quoted_currency' => is_string($quote['currency'] ?? null) ? $quote['currency'] : null,
            'expires_at' => $this->expiresAt($response['expires_at'] ?? null),
        ])->save();

        MarketplaceInstallFlowSessionTransitionAction::run($session, MarketplaceInstallFlowSessionStatus::Redirected, 'created');

        return $approvalUrl;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        throw_unless(is_string($value) && $value !== '', RuntimeException::class, sprintf('Marketplace did not return %s.', $key));

        return $value;
    }

    private function expiresAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return CarbonImmutable::now()->addMinutes(20);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return CarbonImmutable::now()->addMinutes(20);
        }
    }
}
