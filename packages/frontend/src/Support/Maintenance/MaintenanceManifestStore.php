<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Maintenance;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class MaintenanceManifestStore
{
    public function path(): string
    {
        return storage_path('framework/capell-maintenance.json');
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (! File::exists($this->path())) {
            return $this->defaults();
        }

        $decoded = json_decode(File::get($this->path()), true, flags: JSON_THROW_ON_ERROR);
        throw_unless(is_array($decoded) && is_array($decoded['sites'] ?? []), RuntimeException::class, 'Invalid maintenance manifest.');

        return array_replace_recursive($this->defaults(), $decoded);
    }

    /** @param array<string, mixed> $manifest */
    public function needsRoutingUpgrade(array $manifest): bool
    {
        if (($manifest['version'] ?? null) !== 2) {
            return true;
        }

        foreach (($manifest['sites'] ?? []) as $site) {
            $domains = is_array($site) ? ($site['domains'] ?? []) : [];
            if (! is_array($domains)) {
                return true;
            }

            foreach ($domains as $domain) {
                if (is_array($domain) && (! array_key_exists('port', $domain)
                    || ($domain['port'] !== null && ! is_int($domain['port'])))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $manifest */
    public function write(array $manifest): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::replace($this->path(), JsonCodec::encode(array_replace_recursive($this->defaults(), $manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<int, array<string, mixed>> $domains */
    public function replaceSiteDomains(int $siteId, array $domains): void
    {
        $manifest = $this->read();
        $siteKey = (string) $siteId;
        $domainKeys = collect($domains)
            ->filter(fn (mixed $domain): bool => is_array($domain))
            ->map(fn (array $domain): string => $this->domainKey($domain))
            ->filter()
            ->values()
            ->all();

        foreach (($manifest['sites'] ?? []) as $existingSiteKey => $site) {
            if ((string) $existingSiteKey === $siteKey) {
                continue;
            }

            if (! is_array($site)) {
                continue;
            }

            $site['domains'] = collect($site['domains'] ?? [])
                ->filter(fn (mixed $domain): bool => ! is_array($domain) || ! in_array($this->domainKey($domain), $domainKeys, true))
                ->values()
                ->all();

            $manifest['sites'][(string) $existingSiteKey] = $site;
        }

        $manifest['sites'][$siteKey] ??= ['active' => false, 'domains' => []];
        $manifest['sites'][$siteKey]['domains'] = $domains;

        // Do not stamp partially converted manifests as current.
        $candidate = array_replace($manifest, ['version' => 2]);
        if (! $this->needsRoutingUpgrade($candidate)) {
            $manifest = $candidate;
        }

        $this->write($manifest);
    }

    public function setGlobalActive(bool $active): void
    {
        $manifest = $this->read();
        $manifest['global_active'] = $active;

        $this->write($manifest);
    }

    public function setSiteActive(int $siteId, bool $active): void
    {
        $manifest = $this->read();
        $siteKey = (string) $siteId;

        $manifest['sites'][$siteKey] ??= ['active' => false, 'domains' => []];
        $manifest['sites'][$siteKey]['active'] = $active;

        $this->write($manifest);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'version' => 1,
            'global_active' => false,
            'fallback' => null,
            'sites' => [],
        ];
    }

    /** @param array<string, mixed> $domain */
    private function domainKey(array $domain): string
    {
        return implode('|', [
            (string) ($domain['scheme'] ?? ''),
            strtolower((string) ($domain['domain'] ?? '')),
            (string) ($domain['port'] ?? 'default'),
            $this->normalizePath($domain['path'] ?? '/'),
        ]);
    }

    private function normalizePath(mixed $path): string
    {
        if (! is_string($path) || $path === '') {
            return '/';
        }

        return '/' . trim($path, '/');
    }
}
