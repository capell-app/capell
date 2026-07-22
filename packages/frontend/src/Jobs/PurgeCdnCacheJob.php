<?php

declare(strict_types=1);

namespace Capell\Frontend\Jobs;

use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;

class PurgeCdnCacheJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /**
     * @param  array<string>  $surrogateKeys
     */
    private array $surrogateKeys;

    /**
     * @param  array<string>  $surrogateKeys
     */
    public function __construct(array $surrogateKeys)
    {
        $this->surrogateKeys = SurrogateKeyNormalizer::normalize($surrogateKeys);
    }

    public static function hasConfiguredProvider(): bool
    {
        return self::configuredProvider() !== null;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function uniqueId(): string
    {
        $surrogateKeys = $this->surrogateKeys;
        sort($surrogateKeys);

        return hash('sha256', implode("\0", $surrogateKeys));
    }

    /**
     * Purge CDN cache via surrogate keys.
     *
     * Supports:
     * - Cloudflare: POST /purge_cache
     * - Fastly: POST /purge with soft-purge
     * - Varnish: BAN request
     * - Manual: extend this class for custom providers
     */
    public function handle(): void
    {
        if ($this->surrogateKeys === []) {
            Log::info('CDN purge requested but no valid surrogate keys were provided');

            return;
        }

        $provider = self::configuredProvider();

        if ($provider === null) {
            Log::info('CDN purge requested but no provider configured', [
                'keys' => $this->surrogateKeys,
            ]);

            return;
        }

        match ($provider) {
            'cloudflare' => $this->purgeCloudflare(),
            'fastly' => $this->purgeFastly(),
            'varnish' => $this->purgeVarnish(),
            default => throw new LogicException('Unknown CDN provider: ' . $provider),
        };
    }

    private static function configuredProvider(): ?string
    {
        $provider = config('capell-frontend.cdn_provider');

        if (! is_string($provider) || trim($provider) === '') {
            return null;
        }

        return $provider;
    }

    private function purgeCloudflare(): void
    {
        $token = config('capell-frontend.cloudflare_purge_token');
        $zone = config('capell-frontend.cloudflare_zone_id');

        if (! is_string($token) || $token === '' || ! is_string($zone) || $zone === '') {
            throw new LogicException('Cloudflare purge configured but missing credentials');
        }

        try {
            Http::withToken($token)
                ->timeout(10)
                ->post(sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $zone), [
                    'tags' => $this->surrogateKeys,
                ])
                ->throw();

            Log::info('Cloudflare CDN purge successful', ['keys' => $this->surrogateKeys]);
        } catch (Exception $exception) {
            Log::error('Cloudflare CDN purge failed', [
                'keys' => $this->surrogateKeys,
                'error' => $exception->getMessage(),
            ]);

            // Re-throw to allow job retry
            throw $exception;
        }
    }

    private function purgeFastly(): void
    {
        $key = config('capell-frontend.fastly_api_key');
        $serviceId = config('capell-frontend.fastly_service_id');

        if (! is_string($key) || $key === '') {
            throw new LogicException('Fastly purge configured but missing API key');
        }

        try {
            if (is_string($serviceId) && $serviceId !== '') {
                Http::withHeaders([
                    'Fastly-Key' => $key,
                    'Fastly-Soft-Purge' => '1',
                ])
                    ->timeout(10)
                    ->post('https://api.fastly.com/service/' . rawurlencode($serviceId) . '/purge', [
                        'surrogate_keys' => $this->surrogateKeys,
                    ])
                    ->throw();

                Log::info('Fastly CDN purge successful', ['keys' => $this->surrogateKeys]);

                return;
            }

            foreach ($this->surrogateKeys as $surrogateKey) {
                Http::withHeaders([
                    'Fastly-Key' => $key,
                    'Soft-Purge' => '1',
                ])
                    ->timeout(10)
                    ->send('PURGE', 'https://api.fastly.com/purge/' . $surrogateKey)
                    ->throw();
            }

            Log::info('Fastly CDN purge successful', ['keys' => $this->surrogateKeys]);
        } catch (Exception $exception) {
            Log::error('Fastly CDN purge failed', [
                'keys' => $this->surrogateKeys,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function purgeVarnish(): void
    {
        $url = config('capell-frontend.varnish_url');

        if (! is_string($url) || $url === '') {
            throw new LogicException('Varnish purge configured but missing URL');
        }

        try {
            Http::withHeaders(['X-Surrogate-Key' => implode(',', $this->surrogateKeys)])
                ->timeout(10)
                ->send('BAN', $url)
                ->throw();

            Log::info('Varnish CDN purge successful', ['keys' => $this->surrogateKeys]);
        } catch (Exception $exception) {
            Log::error('Varnish CDN purge failed', [
                'keys' => $this->surrogateKeys,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
