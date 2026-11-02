<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Settings\CoreSettings;
use Illuminate\Support\Str;
use Throwable;

final class ImageUrlPolicy
{
    /** @var list<string>|null */
    private ?array $allowedDomains = null;

    /** @var list<string>|null */
    private ?array $ownHosts = null;

    private ?bool $allowRelativeUrls = null;

    private ?CoreSettings $settings = null;

    private bool $settingsResolved = false;

    /**
     * @param  list<string>|null  $allowedDomains
     */
    public function allows(string $url, ?array $allowedDomains = null, ?bool $allowRelative = null): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        $allowedDomains ??= $this->allowedDomains();
        $allowRelative ??= $this->allowsRelativeUrls();

        if ($allowRelative && $this->isRelativeUrl($url)) {
            return true;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (in_array($host, $this->ownHosts(), true)) {
            return true;
        }

        foreach ($allowedDomains as $domain) {
            $normalizedDomain = strtolower(ltrim(trim($domain), '.'));

            if ($normalizedDomain === '') {
                continue;
            }

            if ($host === $normalizedDomain || Str::endsWith($host, '.' . $normalizedDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function allowedDomains(): array
    {
        if ($this->allowedDomains !== null) {
            return $this->allowedDomains;
        }

        try {
            $settings = $this->settings();

            if (! $settings instanceof CoreSettings) {
                return $this->allowedDomains = ['images.unsplash.com'];
            }

            return $this->allowedDomains = array_values(array_filter(
                array_map(trim(...), $settings->allowed_remote_image_domains),
                static fn (string $domain): bool => $domain !== '',
            ));
        } catch (Throwable) {
            return $this->allowedDomains = ['images.unsplash.com'];
        }
    }

    /**
     * Hosts the site itself serves from: app.url, asset_url and every
     * configured filesystem disk's public url. Always allowed; the configured
     * remote domains stay the opt-in list for third-party hosts.
     *
     * @return list<string>
     */
    public function ownHosts(): array
    {
        if ($this->ownHosts !== null) {
            return $this->ownHosts;
        }

        $urls = [config('app.url'), config('app.asset_url')];

        foreach ((array) config('filesystems.disks', []) as $disk) {
            $urls[] = is_array($disk) ? ($disk['url'] ?? null) : null;
        }

        $hosts = [];

        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            if (trim($url) === '') {
                continue;
            }

            $host = parse_url(trim($url), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return $this->ownHosts = array_values(array_unique($hosts));
    }

    public function allowsRelativeUrls(): bool
    {
        if ($this->allowRelativeUrls !== null) {
            return $this->allowRelativeUrls;
        }

        try {
            $settings = $this->settings();

            if (! $settings instanceof CoreSettings) {
                return $this->allowRelativeUrls = true;
            }

            return $this->allowRelativeUrls = $settings->allow_relative_image_urls;
        } catch (Throwable) {
            return $this->allowRelativeUrls = true;
        }
    }

    private function settings(): ?CoreSettings
    {
        if ($this->settingsResolved) {
            return $this->settings;
        }

        $this->settingsResolved = true;

        try {
            return $this->settings = resolve(CoreSettings::class);
        } catch (Throwable) {
            return null;
        }
    }

    private function isRelativeUrl(string $url): bool
    {
        if (! str_starts_with($url, '/')) {
            return false;
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        return ! Str::contains($url, ['\\', "\n", "\r"]);
    }
}
