<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Data\Media\MediaCompositionGuidanceData;
use Capell\Core\Enums\Media\MediaCompositionTemplateStatus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Registry of optional media composition guidance. Dimensions always come from
 * MediaCropPresetRepository so there is a single preset system.
 */
final class MediaCompositionGuidanceRegistry
{
    /** Raster formats whose pixel dimensions must match the preset. */
    public const array RASTER_FORMATS = ['png', 'jpg', 'jpeg', 'webp'];

    /** Document formats accepted as templates without a pixel-dimension check. */
    public const array DOCUMENT_FORMATS = ['svg', 'pdf'];

    /** @var array<string, MediaCompositionGuidanceData> */
    private array $guidance = [];

    public function __construct(private readonly MediaCropPresetRepository $presets) {}

    public function register(MediaCompositionGuidanceData $guidance): void
    {
        foreach (['key', 'label', 'preset', 'templatePath', 'templateVersion', 'instructions', 'templateDisk'] as $property) {
            if (trim($guidance->{$property}) === '') {
                throw new InvalidArgumentException(sprintf('Media composition guidance requires a non-empty [%s].', $property));
            }
        }

        if (str_contains($guidance->templatePath, '..') || str_starts_with($guidance->templatePath, '/')) {
            throw new InvalidArgumentException(sprintf('Media composition guidance [%s] template path must be relative to its disk.', $guidance->key));
        }

        $this->guidance[$guidance->key] = $guidance;
    }

    public function has(string $key): bool
    {
        return isset($this->guidance[$key]);
    }

    public function find(string $key): ?MediaCompositionGuidanceData
    {
        return $this->guidance[$key] ?? null;
    }

    /**
     * @return array<string, MediaCompositionGuidanceData>
     */
    public function all(): array
    {
        return $this->guidance;
    }

    /**
     * @return array{label: string, ratio: string, width: int, height: int}|null
     */
    public function presetFor(MediaCompositionGuidanceData $guidance): ?array
    {
        return $this->presets->find($guidance->preset);
    }

    public function status(MediaCompositionGuidanceData $guidance): MediaCompositionTemplateStatus
    {
        $preset = $this->presetFor($guidance);

        if ($preset === null) {
            return MediaCompositionTemplateStatus::UnknownPreset;
        }

        foreach ($guidance->quietRegions as $region) {
            if (! $region->fitsWithin($preset['width'], $preset['height'])) {
                return MediaCompositionTemplateStatus::InvalidQuietRegion;
            }
        }

        $extension = $guidance->templateExtension();
        $isRaster = in_array($extension, self::RASTER_FORMATS, true);

        if (! $isRaster && ! in_array($extension, self::DOCUMENT_FORMATS, true)) {
            return MediaCompositionTemplateStatus::UnsupportedFormat;
        }

        try {
            $disk = Storage::disk($guidance->templateDisk);

            if (! $disk->exists($guidance->templatePath)) {
                return MediaCompositionTemplateStatus::Missing;
            }

            if (! $isRaster) {
                return MediaCompositionTemplateStatus::Available;
            }

            $contents = $disk->get($guidance->templatePath);
        } catch (Throwable) {
            return MediaCompositionTemplateStatus::Missing;
        }

        if (! is_string($contents) || $contents === '') {
            return MediaCompositionTemplateStatus::Missing;
        }

        $size = @getimagesizefromstring($contents);

        if ($size === false) {
            return MediaCompositionTemplateStatus::UnsupportedFormat;
        }

        return $size[0] === $preset['width'] && $size[1] === $preset['height']
            ? MediaCompositionTemplateStatus::Available
            : MediaCompositionTemplateStatus::DimensionMismatch;
    }

    public function templateUrl(MediaCompositionGuidanceData $guidance): ?string
    {
        try {
            $url = Storage::disk($guidance->templateDisk)->url($guidance->templatePath);
        } catch (Throwable) {
            return null;
        }

        return $url . (str_contains((string) $url, '?') ? '&' : '?') . 'v=' . rawurlencode($guidance->templateVersion);
    }
}
