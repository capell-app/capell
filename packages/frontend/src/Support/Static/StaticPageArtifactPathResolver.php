<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Static;

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StaticPageArtifactPathResolver
{
    public function pathForPageUrl(PageUrl $pageUrl, SiteDomain $siteDomain): string
    {
        $host = $this->safeSegment(strtolower($siteDomain->scheme . '.' . $siteDomain->domain));
        $segments = [
            ...$this->safePathSegments((string) $siteDomain->path),
            ...$this->safePathSegments($this->urlPath($pageUrl->url)),
        ];
        $path = implode('/', $segments);

        if ($path === '') {
            return $host . '/index.html';
        }

        return $host . '/' . Str::finish($path, '/index.html');
    }

    /**
     * @return array<int, string>
     */
    private function safePathSegments(string $path): array
    {
        return collect(explode('/', trim($path, '/')))
            ->filter(fn (string $segment): bool => $segment !== '')
            ->map(fn (string $segment): string => $this->safeSegment(rawurldecode($segment)))
            ->values()
            ->all();
    }

    private function safeSegment(string $segment): string
    {
        throw_if(in_array($segment, ['', '.', '..'], true)
            || str_contains($segment, "\0")
            || str_contains($segment, '/')
            || str_contains($segment, '\\'), InvalidArgumentException::class, 'Static artifact paths may not contain unsafe path segments.');

        return $segment;
    }

    private function urlPath(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            $path = parse_url($url, PHP_URL_PATH);

            return is_string($path) ? $path : '/';
        }

        return $url;
    }
}
