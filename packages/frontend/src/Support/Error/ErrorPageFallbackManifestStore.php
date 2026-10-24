<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Illuminate\Support\Facades\File;

final class ErrorPageFallbackManifestStore
{
    public function path(): string
    {
        return ErrorPageManifestLocation::fallback();
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (! File::exists($this->path())) {
            return $this->defaults();
        }

        $decoded = JsonCodec::decodeArray(File::get($this->path()), $this->defaults());

        return array_replace_recursive($this->defaults(), $decoded);
    }

    /** @param array<string, mixed> $manifest */
    public function write(array $manifest): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::replace($this->path(), JsonCodec::encode(array_replace_recursive($this->defaults(), $manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<int, array<string, string>> $copy */
    public function setHost(string $host, string $logoUrl, array $copy): void
    {
        $manifest = $this->read();
        $manifest['explicit_overrides']['hosts'][strtolower($host)] = [
            'logo_url' => $logoUrl,
            'copy' => $copy,
        ];

        $this->write($manifest);
    }

    /** @param array<int, array<string, string>> $copy */
    public function setDefault(string $logoUrl, array $copy): void
    {
        $manifest = $this->read();
        $manifest['explicit_overrides']['default'] = [
            'logo_url' => $logoUrl,
            'copy' => $copy,
        ];

        $this->write($manifest);
    }

    /** @param array<int, array<string, mixed>> $domains */
    public function replaceSite(int $siteId, array $domains): void
    {
        $manifest = $this->read();
        // Generated branding is route-scoped. Never let the last regenerated
        // site become the fallback for unrelated hosts or mounted sites.
        if (! isset($manifest['version'])) {
            // Unversioned host/default values came from the old generation pipeline.
            $manifest['hosts'] = [];
            $manifest['default'] = ['logo_url' => null, 'copy' => []];
        }

        $manifest['version'] = 2;
        foreach (($manifest['sites'] ?? []) as $key => $site) {
            if (! is_array($site)) {
                continue;
            }

            if ((string) $key === (string) $siteId) {
                continue;
            }

            $site['domains'] = array_values(array_filter($site['domains'] ?? [], fn (array $existing): bool => ! array_any(
                $domains,
                fn (array $domain): bool => $this->sameRoute($existing, $domain),
            )));
            $manifest['sites'][$key] = $site;
        }

        $manifest['sites'][(string) $siteId] = ['domains' => $domains];
        $this->write($manifest);
    }

    /** @param array<string, mixed> $domain */
    private function routingIdentity(array $domain): string
    {
        return SiteDomainAddressing::routingIdentity(
            $domain['scheme'] ?? null,
            $domain['domain'] ?? null,
            $domain['port'] ?? null,
            $domain['path'] ?? '/',
        );
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    private function sameRoute(array $first, array $second): bool
    {
        return (isset($first['site_domain_id'], $second['site_domain_id'])
            && (string) $first['site_domain_id'] === (string) $second['site_domain_id'])
            || $this->routingIdentity($first) === $this->routingIdentity($second);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'default' => ['logo_url' => null, 'copy' => []],
            'hosts' => [],
        ];
    }
}
