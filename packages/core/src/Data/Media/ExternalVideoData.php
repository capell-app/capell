<?php

declare(strict_types=1);

namespace Capell\Core\Data\Media;

final readonly class ExternalVideoData
{
    public function __construct(
        public string $provider,
        public string $videoId,
        public string $url,
        public string $embedUrl,
        public string $thumbnailUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $provider = $data['provider'] ?? null;
        $videoId = $data['video_id'] ?? null;
        $url = $data['url'] ?? null;
        $embedUrl = $data['embed_url'] ?? null;
        $thumbnailUrl = $data['thumbnail_url'] ?? null;

        if (
            ! is_string($provider) || $provider === ''
            || ! is_string($videoId) || $videoId === ''
            || ! is_string($url) || $url === ''
            || ! is_string($embedUrl) || $embedUrl === ''
            || ! is_string($thumbnailUrl) || $thumbnailUrl === ''
        ) {
            return null;
        }

        return new self(
            provider: $provider,
            videoId: $videoId,
            url: $url,
            embedUrl: $embedUrl,
            thumbnailUrl: $thumbnailUrl,
        );
    }

    /**
     * @return array{provider: string, video_id: string, url: string, embed_url: string, thumbnail_url: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'video_id' => $this->videoId,
            'url' => $this->url,
            'embed_url' => $this->embedUrl,
            'thumbnail_url' => $this->thumbnailUrl,
        ];
    }
}
