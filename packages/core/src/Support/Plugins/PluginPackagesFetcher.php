<?php

declare(strict_types=1);

namespace Capell\Core\Support\Plugins;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Http\OutboundHttpRetry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PluginPackagesFetcher
{
    private const int MAX_RESPONSE_BYTES = 1048576;

    private const int CACHE_SCHEMA = 2;

    /** @return Collection<int, array<string, mixed>> */
    public function fetch(bool $force = false): Collection
    {
        $cacheKey = CacheEnum::ExtensionPackages->value;
        $ttl = config('capell.plugins_cache_ttl', 3600);

        if (! $force) {
            $cached = $this->getCached();
            if ($cached->isNotEmpty()) {
                return $cached;
            }
        }

        $url = config('capell.plugins_source_url');

        if (! is_string($url) || ! $this->isSafeSourceUrl($url)) {
            Log::warning('Unsafe plugin packages source URL rejected', ['url' => $url]);

            return collect();
        }

        $packages = collect();
        $lastPage = 1;

        // Construct each page against the validated source. Never follow remote
        // pagination URLs, which could send the next request to a private host.
        for ($page = 1; $page <= $lastPage; $page++) {
            $pageUrl = $page === 1 ? $url : $url . (str_contains($url, '?') ? '&' : '?') . 'page=' . $page;
            $response = OutboundHttpRetry::fromConfig('capell.plugins_http')
                ->apply(Http::acceptJson()->connectTimeout(5)->timeout(10)->withOptions(['allow_redirects' => false]))
                ->get($pageUrl);

            if (! $response->ok()) {
                return collect();
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                Log::warning('Plugin packages response exceeded maximum size', ['url' => $pageUrl]);

                return collect();
            }

            $data = json_decode($body, true);
            if (! is_array($data)) {
                return collect();
            }

            $rawPackages = $data['packages'] ?? $data['data'] ?? (array_is_list($data) ? $data : null);
            if (! is_array($rawPackages)) {
                Log::warning('Invalid JSON structure for plugin packages', ['url' => $pageUrl]);

                return collect();
            }

            if (isset($data['data'])) {
                $lastPage = $data['meta']['last_page'] ?? 1;
                if (! is_int($lastPage) || $lastPage < $page || $lastPage > 20) {
                    return collect();
                }
            }

            foreach ($rawPackages as $item) {
                if (is_array($item)) {
                    $packages->push(isset($data['data']) ? $this->cataloguePackage($item) : $item);
                }
            }
        }

        CapellCore::setToCache($cacheKey, $packages, $ttl);
        CapellCore::setToCache($cacheKey . '-source', $url, $ttl);
        CapellCore::setToCache($cacheKey . '-schema', self::CACHE_SCHEMA, $ttl);

        return $packages;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getCached(): Collection
    {
        if (CapellCore::getFromCache(CacheEnum::ExtensionPackages->value . '-source') !== config('capell.plugins_source_url')
            || CapellCore::getFromCache(CacheEnum::ExtensionPackages->value . '-schema') !== self::CACHE_SCHEMA) {
            return collect();
        }

        $cached = CapellCore::getFromCache(CacheEnum::ExtensionPackages->value);

        if ($cached instanceof Collection) {
            return $cached;
        }

        if (is_array($cached)) {
            return collect($cached);
        }

        return collect();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function cataloguePackage(array $item): array
    {
        $manifest = is_array($item['manifest'] ?? null) ? $item['manifest'] : [];
        $dependencies = is_array($item['dependencies'] ?? null) ? $item['dependencies'] : [];

        return [
            'name' => $item['composer_name'] ?? null,
            'description' => $manifest['description'] ?? $item['description'] ?? null,
            'version' => $item['latest_version'] ?? null,
            'tier' => $item['product_tier'] ?? null,
            'isPaid' => is_bool($item['is_paid'] ?? null) ? $item['is_paid'] : null,
            'installState' => is_string($item['install_state'] ?? null) ? $item['install_state'] : null,
            'installEligibility' => is_array($item['install_eligibility'] ?? null) ? $item['install_eligibility'] : null,
            'purchaseUrl' => is_string($item['purchase_url'] ?? null) ? $item['purchase_url'] : null,
            'slug' => is_string($item['slug'] ?? null) ? $item['slug'] : null,
            'productGroup' => $item['product_group'] ?? null,
            'bundle' => $item['product_bundle'] ?? null,
            'requirements' => $dependencies['requires'] ?? [],
            'type' => ($item['kind'] ?? null) === 'theme' ? 'theme' : 'plugin',
            'kind' => $item['kind'] ?? null,
            'themeKey' => $manifest['theme']['key'] ?? $manifest['themeKey'] ?? null,
            'extendsPackage' => $manifest['theme']['extends'] ?? $manifest['extends'] ?? null,
            'visibility' => $manifest['visibility'] ?? 'catalogue',
        ];
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
