<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Data\Media\ExternalVideoData;

final class YouTubeVideoUrl
{
    public const string Provider = 'youtube';

    public static function parse(string $url): ?ExternalVideoData
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $videoId = self::videoId($url);

        if ($videoId === null) {
            return null;
        }

        return new ExternalVideoData(
            provider: self::Provider,
            videoId: $videoId,
            url: $url,
            embedUrl: sprintf('https://www.youtube-nocookie.com/embed/%s?enablejsapi=1&rel=0&playsinline=1', $videoId),
            thumbnailUrl: sprintf('https://img.youtube.com/vi/%s/hqdefault.jpg', $videoId),
        );
    }

    private static function videoId(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        $candidate = match (true) {
            $host === 'youtu.be' => trim($path, '/'),
            in_array($host, ['youtube.com', 'm.youtube.com'], true) => self::youtubeDotComVideoId($path, (string) ($parts['query'] ?? '')),
            $host === 'youtube-nocookie.com' => self::embedPathVideoId($path),
            default => null,
        };

        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) === 1 ? $candidate : null;
    }

    private static function youtubeDotComVideoId(string $path, string $query): ?string
    {
        $path = trim($path, '/');

        if ($path === 'watch') {
            parse_str($query, $parameters);
            $videoId = $parameters['v'] ?? null;

            return is_string($videoId) ? $videoId : null;
        }

        if (str_starts_with($path, 'embed/') || str_starts_with($path, 'shorts/')) {
            return self::embedPathVideoId('/' . $path);
        }

        return null;
    }

    private static function embedPathVideoId(string $path): ?string
    {
        $segments = explode('/', trim($path, '/'));

        if (count($segments) < 2) {
            return null;
        }

        if (! in_array($segments[0], ['embed', 'shorts'], true)) {
            return null;
        }

        return $segments[1];
    }
}
