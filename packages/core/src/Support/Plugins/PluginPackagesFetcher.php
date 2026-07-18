<?php

declare(strict_types=1);

namespace Capell\Core\Support\Plugins;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PluginPackagesFetcher
{
    private const int MAX_RESPONSE_BYTES = 1048576;

    /** @return Collection<int, array<string, mixed>> */
    public function fetch(bool $force = false): Collection
    {
        $cacheKey = CacheEnum::ExtensionPackages->value;
        $ttl = config('capell.plugins_cache_ttl', 3600);

        if (! $force) {
            $cached = CapellCore::getFromCache($cacheKey);

            if ($cached instanceof Collection) {
                return $cached;
            }

            if (is_array($cached)) {
                return collect($cached);
            }
        }

        $url = config('capell.plugins_source_url');

        if (! $this->isSafeSourceUrl($url)) {
            Log::warning('Unsafe plugin packages source URL rejected', ['url' => $url]);

            return collect();
        }

        $response = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(10)
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        if (! $response->ok()) {
            return collect();
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            Log::warning('Plugin packages response exceeded maximum size', ['url' => $url]);

            return collect();
        }

        $data = json_decode($body, true);
        $rawPackages = null;

        // Accept either keyed structure ['packages' => [...]] or a flat array of packages
        if (is_array($data)) {
            if (isset($data['packages']) && is_array($data['packages'])) {
                $rawPackages = $data['packages'];
            } elseif ($data !== [] && array_is_list($data) && is_array($data[0])) { // flat list of associative arrays
                $rawPackages = $data;
            } elseif ($data === []) { // empty list is valid
                $rawPackages = [];
            }
        }

        if ($rawPackages === null) {
            Log::warning('Invalid JSON structure for plugin packages', ['url' => $url]);

            return collect();
        }

        // Normalize items into a collection of associative arrays
        /** @var Collection<int, array<string, mixed>> $packages */
        $packages = collect($rawPackages)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        CapellCore::setToCache($cacheKey, $packages, $ttl);

        return $packages;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getCached(): Collection
    {
        $cached = CapellCore::getFromCache(CacheEnum::ExtensionPackages->value);

        if ($cached instanceof Collection) {
            return $cached;
        }

        if (is_array($cached)) {
            return collect($cached);
        }

        return collect();
    }

    private function isSafeSourceUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));
        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }

        if ($this->isTestingHost($host)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $dnsRecords = dns_get_record($host, DNS_A + DNS_AAAA);
            $addresses = $dnsRecords === false ? [] : $dnsRecords;
        }

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            $ip = is_array($address) ? ($address['ip'] ?? $address['ipv6'] ?? null) : $address;
            if (! is_string($ip) || ! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isTestingHost(string $host): bool
    {
        return app()->environment('testing') && str_ends_with($host, '.test');
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
