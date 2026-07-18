<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Events\UrlVisitFailed;
use Capell\Core\Models\SiteDomain;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(string $url, ?int $pageId = null)
 */
final class VisitUrlAction implements ShouldBeUnique
{
    use AsFake;
    use AsJob;
    use AsObject;

    protected string $url;

    public function getJobUniqueId(string $url, ?int $pageId = null): string
    {
        return 'visit_url_' . hash('sha256', $url . '|' . $pageId);
    }

    public function handle(string $url, ?int $pageId = null): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            Log::warning('VisitUrlAction: rejected non-http(s) url', ['url' => $url, 'scheme' => $scheme]);

            return;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '' || ! $this->isAllowedHost($host)) {
            Log::warning('VisitUrlAction: rejected unsafe url', ['url' => $url, 'host' => $host]);

            return;
        }

        $safeAddress = $this->safeResolvedAddress($host);
        if ($safeAddress === null) {
            Log::warning('VisitUrlAction: rejected unsafe url', ['url' => $url, 'host' => $host]);

            return;
        }

        $response = Http::withOptions($this->pinnedDnsOptions($url, $host, $safeAddress))
            ->connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->get($url);

        if (! $response->ok()) {
            Log::info('Problem accessing url', ['url' => $url, 'status' => $response->status()]);
            event(new UrlVisitFailed($url, $response->status(), $pageId));
        }
    }

    private function isAllowedHost(string $host): bool
    {
        if (SiteDomain::query()
            ->where('domain', $host)
            ->enabled()
            ->exists()) {
            return true;
        }

        $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host === $appUrlHost
            && SiteDomain::query()
                ->whereNull('domain')
                ->enabled()
                ->exists();
    }

    private function safeResolvedAddress(string $host): ?string
    {
        if ($this->isUnsafeAddress($host)) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $addresses = gethostbynamel($host);
        if ($addresses === false || $addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if ($this->isUnsafeAddress($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function pinnedDnsOptions(string $url, string $host, string $safeAddress): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT);
        if (! is_int($port)) {
            $port = $scheme === 'http' ? 80 : 443;
        }

        return [
            'curl' => [
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $safeAddress)],
            ],
        ];
    }

    private function isUnsafeAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
