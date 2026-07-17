<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallFlowResultData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class CompleteMarketplaceInstallFlowAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplaceInstanceResolver $instances,
    ) {}

    public function handle(string $flowId, string $code, string $state): MarketplaceInstallFlowSession
    {
        $session = $this->reserveSession($flowId, $state);

        try {
            $result = MarketplaceInstallFlowResultData::fromApiResponse($this->marketplace->exchangeInstallFlow([
                'flow_id' => $flowId,
                'code' => $code,
                'state' => $state,
                'code_verifier' => $session->code_verifier_encrypted,
            ]));

            if (! $result->canInstall) {
                $session->forceFill([
                    'contract_version' => $result->contractVersion,
                    'quoted_extensions' => $result->quoteExtensions(),
                    'quoted_price_cents' => $result->quotePriceCents(),
                    'quoted_currency' => $result->quoteCurrency(),
                    'remote_entitlement_ids' => $result->entitlements,
                    'last_exchange_payload' => $this->redactedExchangePayload($result),
                    'failure_metadata' => [
                        'eligibility' => $result->eligibility,
                    ],
                ])->save();

                MarketplaceInstallFlowSessionTransitionAction::run(
                    $session,
                    MarketplaceInstallFlowSessionStatus::Failed,
                    $result->blockReason ?? 'install_flow_blocked',
                );

                throw new RuntimeException($result->blockReason ?? 'Marketplace install flow is not eligible for installation.');
            }

            $this->persistConnection($result);
            $this->instances->forget();

            $session->forceFill([
                'contract_version' => $result->contractVersion,
                'quoted_extensions' => $result->quoteExtensions(),
                'quoted_price_cents' => $result->quotePriceCents(),
                'quoted_currency' => $result->quoteCurrency(),
                'remote_entitlement_ids' => $result->entitlements,
                'last_exchange_payload' => $this->redactedExchangePayload($result),
            ])->save();

            MarketplaceInstallFlowSessionTransitionAction::run($session, MarketplaceInstallFlowSessionStatus::Returned, 'authorized');
        } catch (Throwable $throwable) {
            MarketplaceInstallFlowSessionTransitionAction::run($session, MarketplaceInstallFlowSessionStatus::Failed, $throwable->getMessage());

            throw $throwable;
        }

        return $session->refresh();
    }

    private function reserveSession(string $flowId, string $state): MarketplaceInstallFlowSession
    {
        return DB::transaction(function () use ($flowId, $state): MarketplaceInstallFlowSession {
            $session = MarketplaceInstallFlowSession::query()
                ->where('remote_flow_id', $flowId)
                ->where('status', MarketplaceInstallFlowSessionStatus::Redirected)
                ->lockForUpdate()
                ->first();

            throw_unless($session instanceof MarketplaceInstallFlowSession, RuntimeException::class, 'Marketplace install flow session was not found.');
            throw_unless(hash_equals($session->state_hash, hash('sha256', $state)), RuntimeException::class, 'Marketplace install flow state is invalid.');

            if ($session->expires_at instanceof CarbonImmutable && $session->expires_at->isPast()) {
                MarketplaceInstallFlowSessionTransitionAction::run($session, MarketplaceInstallFlowSessionStatus::Expired, 'Marketplace install flow session has expired.');

                throw new RuntimeException('Marketplace install flow session has expired.');
            }

            MarketplaceInstallFlowSessionTransitionAction::run($session, MarketplaceInstallFlowSessionStatus::Authorizing, 'callback_returned');

            return $session->refresh();
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function redactedExchangePayload(MarketplaceInstallFlowResultData $result): array
    {
        return RedactMarketplaceDiagnosticContextAction::run($result->payload);
    }

    private function persistConnection(MarketplaceInstallFlowResultData $result): MarketplaceInstance
    {
        $instanceId = $this->requiredString($result->instance, 'instance_id');
        $signingSecret = $this->requiredString($result->instance, 'signing_secret');
        $accountId = $this->requiredString($result->account, 'account_id');
        $accountEmail = $this->requiredString($result->account, 'account_email');
        $verifiedAt = $this->optionalDate($result->account['account_email_verified_at'] ?? null);

        throw_unless($verifiedAt instanceof CarbonImmutable, RuntimeException::class, 'Your Capell account email must be verified before installing Marketplace packages.');

        return MarketplaceInstance::query()->updateOrCreate(
            ['instance_id' => $instanceId],
            [
                'signing_secret_encrypted' => $signingSecret,
                'connection_mode' => MarketplaceConnectionMode::AccountLinked,
                'account_id' => $accountId,
                'account_name' => is_string($result->account['account_name'] ?? null) ? $result->account['account_name'] : null,
                'account_email' => $accountEmail,
                'account_email_verified_at' => $verifiedAt,
                'connection_metadata' => [
                    'source' => 'marketplace_install_flow',
                    'flow_id' => $result->flowId,
                    'install_flow' => $result->metadata,
                ],
                'connected_at' => now(),
                'last_heartbeat_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        throw_unless(is_string($value) && $value !== '', RuntimeException::class, sprintf('Marketplace install flow did not return %s.', $key));

        return $value;
    }

    private function optionalDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
