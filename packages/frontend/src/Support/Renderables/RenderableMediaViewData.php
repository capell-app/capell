<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Renderables;

use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Models\Media;
use Illuminate\Database\Eloquent\Model;

final class RenderableMediaViewData
{
    /**
     * @return array<int, Media>
     */
    public function mediaFor(Model $asset, ?string $collection = null): array
    {
        if (! $asset->relationLoaded('media')) {
            return [];
        }

        return collect($asset->getRelation('media'))
            ->filter(fn (mixed $media): bool => $media instanceof Media)
            ->filter(fn (Media $media): bool => $collection === null || $media->collection_name === $collection)
            ->values()
            ->all();
    }

    public function firstExternalVideo(Model $asset, string $collection): ?ExternalVideoData
    {
        foreach ($this->mediaFor($asset, $collection) as $media) {
            if ($media->isExternalVideo()) {
                return $media->externalVideo();
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function heroMedia(array $meta): array
    {
        $heroMedia = is_array($meta['hero_media'] ?? null) ? $meta['hero_media'] : [];
        $sources = is_array($heroMedia['sources'] ?? null) ? $heroMedia['sources'] : [];
        $backgroundImage = is_string($meta['background_image'] ?? null)
            ? $meta['background_image']
            : (is_string(data_get($sources, 'desktop.image')) ? data_get($sources, 'desktop.image') : null);
        $backgroundVideo = is_array($meta['background_video'] ?? null) ? $meta['background_video'] : [];
        $mode = is_string($heroMedia['mode'] ?? null) ? $heroMedia['mode'] : 'custom';

        return [
            'sources' => $sources,
            'mode' => $mode,
            'backgroundImage' => $backgroundImage,
            'backgroundVideoUrl' => $mode === 'off' ? '' : (is_string($backgroundVideo['src'] ?? null) ? $backgroundVideo['src'] : (is_string(data_get($sources, 'desktop.video')) ? data_get($sources, 'desktop.video') : '')),
            'backgroundVideoPoster' => is_string($backgroundVideo['poster'] ?? null) ? $backgroundVideo['poster'] : $backgroundImage,
            'imageAlt' => is_string($meta['image_alt'] ?? null) ? $meta['image_alt'] : '',
        ];
    }
}
