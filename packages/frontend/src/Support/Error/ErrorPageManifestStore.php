<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;

final class ErrorPageManifestStore
{
    public function path(): string
    {
        return storage_path('framework/capell-error-pages.json');
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

    /** @param array<int, array<string, mixed>> $entries */
    public function replaceSite(int $siteId, array $entries): void
    {
        $manifest = $this->read();
        $siteKey = (string) $siteId;

        // The regeneration fingerprint describes the entries being replaced, so
        // it is dropped with them and re-recorded once the new artefacts are
        // published. A manifest is never left claiming to be up to date for
        // output it no longer contains.
        $manifest['sites'][$siteKey] = ['entries' => array_values($entries)];

        $this->write($manifest);
    }

    /**
     * The signature of the inputs the site's current entries were rendered from.
     * It lives in the manifest rather than a cache so it shares the artefacts'
     * lifetime: no cache driver, flush or process boundary can leave the
     * change-driven regeneration gate unable to recognise its own output.
     */
    public function fingerprintFor(int $siteId): ?string
    {
        $fingerprint = $this->site($siteId)['fingerprint'] ?? null;

        return is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : null;
    }

    public function rememberFingerprint(int $siteId, string $fingerprint): void
    {
        $manifest = $this->read();
        $siteKey = (string) $siteId;

        $manifest['sites'][$siteKey]['fingerprint'] = $fingerprint;

        $this->write($manifest);
    }

    public function forgetFingerprint(int $siteId): void
    {
        $manifest = $this->read();
        $siteKey = (string) $siteId;

        unset($manifest['sites'][$siteKey]['fingerprint']);

        $this->write($manifest);
    }

    public function entryCountFor(int $siteId): int
    {
        $entries = $this->site($siteId)['entries'] ?? [];

        return is_array($entries) ? count($entries) : 0;
    }

    /** @return array<string, mixed> */
    private function site(int $siteId): array
    {
        $sites = $this->read()['sites'] ?? [];

        if (! is_array($sites)) {
            return [];
        }

        $site = $sites[(string) $siteId] ?? [];

        return is_array($site) ? $site : [];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'sites' => [],
        ];
    }
}
