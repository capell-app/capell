<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Upgrade;

use Capell\Core\Actions\Upgrade\ResolveInstalledComposerVersionsAction;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class CheckCapellApiForUpdatesAction
{
    use AsFake;
    use AsObject;

    public function handle(): bool
    {
        if (! $this->configBoolean('capell-admin.upgrades.api_enabled', true)) {
            return false;
        }

        $apiUrlConfig = config('capell-admin.upgrades.api_url', '');
        $apiUrl = trim(is_string($apiUrlConfig) ? $apiUrlConfig : '');

        if ($apiUrl === '' || $this->isUnsafeUrl($apiUrl)) {
            return false;
        }

        try {
            $response = Http::timeout(config('capell-admin.upgrades.api_timeout_seconds', 10))
                ->acceptJson()
                ->post($apiUrl, [
                    'app_url' => config('app.url'),
                    'capell_version' => CapellCore::getInstalledPrettyVersion('capell-app/capell'),
                    'installed_packages' => ResolveInstalledComposerVersionsAction::run(),
                ]);

            if (! $response->successful()) {
                Log::warning('capell-admin: update API returned failure', ['status' => $response->status()]);

                return false;
            }

            $payload = $response->json('data');

            if (! is_array($payload)) {
                return false;
            }

            $capellVersion = is_string($payload['capell_version'] ?? null) ? $payload['capell_version'] : null;

            RecordUpgradeSnapshotAction::run(
                source: 'capell-api',
                updates: $this->normaliseNotices($payload['updates'] ?? null),
                advisories: $this->normaliseNotices($payload['advisories'] ?? null),
                metadata: array_filter([
                    'response_id' => is_string($payload['response_id'] ?? null) ? $payload['response_id'] : null,
                    'capell_version' => $capellVersion,
                ], fn (mixed $value): bool => $value !== null),
                capellVersion: $capellVersion,
            );
        } catch (Throwable $throwable) {
            Log::warning('capell-admin: update API check failed', ['error' => $throwable->getMessage()]);

            return false;
        }

        return true;
    }

    private function isUnsafeUrl(string $apiUrl): bool
    {
        return $this->configBoolean('capell-admin.upgrades.enforce_https', true)
            && parse_url($apiUrl, PHP_URL_SCHEME) !== 'https';
    }

    private function configBoolean(string $key, bool $default): bool
    {
        $value = config($key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normaliseNotices(mixed $notices): array
    {
        if (! is_array($notices)) {
            return [];
        }

        return array_values(array_filter($notices, is_array(...)));
    }
}
