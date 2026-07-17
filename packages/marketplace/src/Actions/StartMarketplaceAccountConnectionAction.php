<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceAccountConnectionSessionStatus;
use Capell\Marketplace\Models\MarketplaceAccountConnectionSession;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceApprovalUrl;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class StartMarketplaceAccountConnectionAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    public function handle(): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $host = parse_url($appUrl, PHP_URL_HOST);

        throw_if(! is_string($host) || $host === '', RuntimeException::class, 'APP_URL must include a valid host before connecting Marketplace.');

        $state = Str::random(64);
        $codeVerifier = Str::random(96);
        $callbackUrl = $appUrl . route('capell-marketplace.account-connection.callback', [], false);

        $session = MarketplaceAccountConnectionSession::query()->create([
            'state_hash' => hash('sha256', $state),
            'code_verifier_hash' => hash('sha256', $codeVerifier),
            'code_verifier_encrypted' => $codeVerifier,
            'claimed_domain' => $host,
            'app_url' => $appUrl,
            'callback_url' => $callbackUrl,
            'status' => MarketplaceAccountConnectionSessionStatus::Pending,
            'expires_at' => now()->addMinutes(10),
        ]);

        try {
            $response = $this->marketplace->createAccountConnectionSession([
                'app_url' => $appUrl,
                'callback_url' => $callbackUrl,
                'state' => $state,
                'code_challenge' => hash('sha256', $codeVerifier),
                'instance_id' => config('capell-marketplace.instance.id'),
                'capell_version' => $this->installedVersion('capell-app/core'),
                'laravel_version' => app()->version(),
            ]);

            $connectionSessionId = $this->requiredString($response, 'connection_session_id');
            $approvalUrl = MarketplaceApprovalUrl::validate($this->requiredString($response, 'approval_url'));
        } catch (Throwable $throwable) {
            $session->update([
                'status' => MarketplaceAccountConnectionSessionStatus::Failed,
                'last_error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }

        $session->update([
            'connection_session_id' => $connectionSessionId,
            'expires_at' => $this->expiresAt($response['expires_at'] ?? null),
        ]);

        return $approvalUrl;
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

    private function expiresAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return CarbonImmutable::now()->addMinutes(10);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return CarbonImmutable::now()->addMinutes(10);
        }
    }

    private function installedVersion(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($package);
    }
}
