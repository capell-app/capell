<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Data\ImageSourceData;
use Capell\Core\Enums\ImageSourceType;
use Capell\Core\Support\Media\ImageUrlPolicy;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class ResolveImageSourceDataAction
{
    use AsObject;

    public function __construct(private readonly ImageUrlPolicy $urlPolicy) {}

    /**
     * @param  array<string, mixed>|string|MediaContract|null  $source
     */
    public function handle(mixed $source, ?MediaContract $media = null, ?string $alt = null): ?ImageSourceData
    {
        if ($source instanceof MediaContract) {
            return new ImageSourceData(
                type: ImageSourceType::Media,
                media: $source,
                alt: $alt,
                width: $source->getWidth(),
                height: $source->getHeight(),
            );
        }

        if (is_string($source)) {
            return $this->fromUrl($source, $alt);
        }

        if (! is_array($source)) {
            return $media instanceof MediaContract
                ? $this->handle($media, alt: $alt)
                : null;
        }

        $type = $this->resolveType($source);

        if ($type?->storesMediaRelation()) {
            return $media instanceof MediaContract
                ? $this->handle($media, alt: $alt)
                : null;
        }

        if ($type === ImageSourceType::Upload) {
            return $this->fromUpload($source, $alt);
        }

        return $this->fromUrl((string) ($source['url'] ?? ''), $alt);
    }

    private function fromUrl(string $url, ?string $alt): ?ImageSourceData
    {
        if (! $this->urlPolicy->allows($url)) {
            return null;
        }

        return new ImageSourceData(
            type: ImageSourceType::Url,
            url: $url,
            alt: $alt,
        );
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function fromUpload(array $source, ?string $alt): ?ImageSourceData
    {
        $path = $source['path'] ?? $source['upload'] ?? null;

        if (is_array($path)) {
            $path = reset($path) ?: null;
        }

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $disk = is_string($source['disk'] ?? null) ? $source['disk'] : 'public';

        try {
            $url = Storage::disk($disk)->url($path);
        } catch (Throwable) {
            $url = '/storage/' . ltrim($path, '/');
        }

        return new ImageSourceData(
            type: ImageSourceType::Upload,
            url: $url,
            path: $path,
            alt: $alt,
        );
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function resolveType(array $source): ?ImageSourceType
    {
        $type = $source['type'] ?? $source['source'] ?? null;

        if (! is_string($type) || $type === '' || $type === 'auto') {
            return isset($source['url']) ? ImageSourceType::Url : null;
        }

        return ImageSourceType::tryFrom($type);
    }
}
