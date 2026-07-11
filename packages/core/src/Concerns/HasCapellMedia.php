<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\MediaConversionEnum;
use Capell\Core\Support\Media\MediaCropPresetRepository;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Http\UploadedFile;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia as SpatieInteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Default Capell media trait. Applies Spatie's InteractsWithMedia out of the box.
 *
 * Plugins that replace the media backend (e.g. capell/media-library) rebind
 * this trait's behaviour by providing an alternative trait + container binding.
 * Consumer models call the same public API in both cases:
 * getMedia(), getFirstMediaUrl(), addMediaFromUrl(), etc.
 *
 * Models using this trait satisfy Capell\Core\Contracts\Media\HasMediaContract:
 * Spatie's InteractsWithMedia provides getMedia(), getFirstMedia(),
 * getFirstMediaUrl(), clearMediaCollection(); addMediaFromUploadedFile() is
 * shimmed below over Spatie's addMedia()->toMediaCollection() flow. Those
 * models must also implement Spatie\MediaLibrary\HasMedia (Spatie's trait
 * requires it) alongside HasMediaContract.
 */
trait HasCapellMedia
{
    use SpatieInteractsWithMedia;

    public function addMediaFromUploadedFile(UploadedFile $file, string $collection = 'default'): MediaContract
    {
        /** @var MediaContract $media */
        $media = $this->addMedia($file)->toMediaCollection($collection);

        return $media;
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        if (! $media instanceof Media || ! str_starts_with($media->mime_type, 'image/')) {
            return;
        }

        $cropPresets = resolve(MediaCropPresetRepository::class);
        $registeredConversions = [];

        foreach (MediaConversionEnum::cases() as $conversion) {
            $preset = $cropPresets->find($conversion->value);
            $dimensions = $preset ?? $conversion->defaultDimensions();

            $this->registerCapellMediaConversion(
                media: $media,
                name: $conversion->value,
                width: $dimensions['width'],
                height: $dimensions['height'],
                fit: $conversion->fit(),
                format: $conversion->format(),
                shouldCropAroundFocalPoint: $preset !== null || $conversion->fit() === Fit::Crop,
            );

            $registeredConversions[] = $conversion->value;
        }

        foreach ($cropPresets->all() as $name => $preset) {
            if (in_array($name, $registeredConversions, true)) {
                continue;
            }

            $this->registerCapellMediaConversion(
                media: $media,
                name: $name,
                width: $preset['width'],
                height: $preset['height'],
                fit: Fit::Crop,
                format: 'webp',
                shouldCropAroundFocalPoint: true,
            );
        }
    }

    /**
     * @return MorphOne<Media, $this>
     */
    public function morphOneMedia(string $collection = 'default'): MorphOne
    {
        $model = config('media-library.media_model', Media::class);

        /** @var class-string<Media> $model */
        return $this->morphOne($model, 'model')
            ->where('collection_name', $collection)
            ->latestOfMany('order_column');
    }

    private function registerCapellMediaConversion(
        Media $media,
        string $name,
        int $width,
        int $height,
        Fit $fit,
        string $format,
        bool $shouldCropAroundFocalPoint,
    ): void {
        $conversion = $this->addMediaConversion($name);

        if (
            $shouldCropAroundFocalPoint
            && method_exists($media, 'getFocalPointForConversion')
        ) {
            /** @var array{x: int, y: int} $focalPoint */
            $focalPoint = $media->getFocalPointForConversion($name);

            $conversion->focalCropAndResize($width, $height, $focalPoint['x'], $focalPoint['y']);
        } else {
            $conversion->fit($fit, $width, $height);
        }

        $conversion->format($format);
    }
}
