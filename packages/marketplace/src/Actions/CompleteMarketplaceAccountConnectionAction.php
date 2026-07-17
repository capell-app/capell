<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceAccountConnectionSessionStatus;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Models\MarketplaceAccountConnectionSession;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\MarketplaceClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class CompleteMarketplaceAccountConnectionAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    public function handle(string $connectionSessionId, string $code, string $state): MarketplaceInstance
    {
        $session = $this->reservePendingSession($connectionSessionId, $state);

        try {
            $response = $this->marketplace->exchangeAccountConnectionCode([
                'connection_session_id' => $connectionSessionId,
                'code' => $code,
                'state' => $state,
                'code_verifier' => $session->code_verifier_encrypted,
            ]);
        } catch (Throwable $throwable) {
            $session->update([
                'status' => MarketplaceAccountConnectionSessionStatus::Failed,
                'last_error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }

        try {
            return DB::transaction(function () use ($session, $response): MarketplaceInstance {
                $lockedSession = MarketplaceAccountConnectionSession::query()
                    ->whereKey($session->getKey())
                    ->where('status', MarketplaceAccountConnectionSessionStatus::Completing)
                    ->lockForUpdate()
                    ->first();

                throw_unless($lockedSession instanceof MarketplaceAccountConnectionSession, RuntimeException::class, 'Marketplace account connection session is no longer pending completion.');

                return $this->persistConnection($lockedSession, $response);
            }, attempts: 3);
        } catch (Throwable $throwable) {
            $session->update([
                'status' => MarketplaceAccountConnectionSessionStatus::Failed,
                'last_error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }

    private function reservePendingSession(string $connectionSessionId, string $state): MarketplaceAccountConnectionSession
    {
        return DB::transaction(function () use ($connectionSessionId, $state): MarketplaceAccountConnectionSession {
            $session = MarketplaceAccountConnectionSession::query()
                ->where('connection_session_id', $connectionSessionId)
                ->where('status', MarketplaceAccountConnectionSessionStatus::Pending)
                ->lockForUpdate()
                ->first();

            throw_unless($session instanceof MarketplaceAccountConnectionSession, RuntimeException::class, 'Marketplace account connection session was not found.');
            throw_unless(hash_equals($session->state_hash, hash('sha256', $state)), RuntimeException::class, 'Marketplace account connection state is invalid.');

            if ($session->expires_at instanceof CarbonImmutable && $session->expires_at->isPast()) {
                $session->update(['status' => MarketplaceAccountConnectionSessionStatus::Expired]);

                throw new RuntimeException('Marketplace account connection session has expired.');
            }

            $session->update(['status' => MarketplaceAccountConnectionSessionStatus::Completing]);

            return $session->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function persistConnection(MarketplaceAccountConnectionSession $session, array $response): MarketplaceInstance
    {
        $accountEmailVerifiedAt = $this->optionalDate($response['account_email_verified_at'] ?? null);

        throw_if(! $accountEmailVerifiedAt instanceof CarbonImmutable, RuntimeException::class, 'Your Capell account email must be verified before connecting Marketplace.');

        $instanceId = $this->requiredString($response, 'instance_id');
        $signingSecret = $this->requiredString($response, 'signing_secret');
        $accountId = $this->requiredString($response, 'account_id');
        $accountEmail = $this->requiredString($response, 'account_email');
        $diagnostics = is_array($response['diagnostics_summary'] ?? null)
            ? $response['diagnostics_summary']
            : null;

        $instance = MarketplaceInstance::query()->updateOrCreate(
            ['instance_id' => $instanceId],
            [
                'signing_secret_encrypted' => $signingSecret,
                'connection_mode' => MarketplaceConnectionMode::AccountLinked,
                'account_id' => $accountId,
                'account_name' => $this->optionalString($response['account_name'] ?? null),
                'account_email' => $accountEmail,
                'account_email_verified_at' => $accountEmailVerifiedAt,
                'connection_metadata' => [
                    'app_url' => $session->app_url,
                    'diagnostics_summary' => $diagnostics,
                ],
                'connected_at' => now(),
                'last_heartbeat_at' => now(),
            ],
        );

        $session->update([
            'status' => MarketplaceAccountConnectionSessionStatus::Completed,
            'completed_at' => now(),
            'last_error' => null,
        ]);

        return $instance;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        throw_if(! is_string($value) || $value === '', RuntimeException::class, sprintf('Marketplace did not return %s.', $key));

        return $value;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
