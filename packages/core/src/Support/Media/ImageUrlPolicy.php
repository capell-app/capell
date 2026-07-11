<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Octane\Resettable;
use Capell\Core\Settings\CoreSettings;
use Illuminate\Support\Str;
use Throwable;

final class ImageUrlPolicy implements Resettable
{
    /** @var list<string>|null */
    private ?array $allowedDomains = null;

    private ?bool $allowRelativeUrls = null;

    public function flushOctaneState(): void
    {
        $this->allowedDomains = null;
        $this->allowRelativeUrls = null;
    }

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
            $settings = resolve(CoreSettings::class);
            $domains = $settings->allowed_remote_image_domains;
        } catch (Throwable) {
            $domains = ['images.unsplash.com'];
        }

        if (is_string($domains)) {
            $domains = preg_split('/[\s,]+/', $domains) ?: [];
        }

        if (! is_array($domains)) {
            return $this->allowedDomains = ['images.unsplash.com'];
        }

        return $this->allowedDomains = array_values(array_filter(
            array_map(trim(...), $domains),
            static fn (string $domain): bool => $domain !== '',
        ));
    }

    public function allowsRelativeUrls(): bool
    {
        if ($this->allowRelativeUrls !== null) {
            return $this->allowRelativeUrls;
        }

        try {
            $settings = resolve(CoreSettings::class);

            return $this->allowRelativeUrls = $settings->allow_relative_image_urls;
        } catch (Throwable) {
            return $this->allowRelativeUrls = true;
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
