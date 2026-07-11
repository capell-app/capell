<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Enums\ImageSourceType;
use InvalidArgumentException;

final class ImageSourcePresets
{
    /**
     * @param  list<ImageSourceType|string>|string|ImageSourceType|null  $sources
     * @return list<ImageSourceType>
     */
    public static function resolve(string|array|ImageSourceType|null $sources): array
    {
        if ($sources instanceof ImageSourceType) {
            return [$sources];
        }

        if ($sources === null || $sources === 'all') {
            return [ImageSourceType::Url, ImageSourceType::Upload, ImageSourceType::Media];
        }

        if (is_string($sources)) {
            $sources = match ($sources) {
                'url_only' => [ImageSourceType::Url],
                'upload_only' => [ImageSourceType::Upload],
                'media_only' => [ImageSourceType::Media],
                'url_media' => [ImageSourceType::Url, ImageSourceType::Media],
                'upload_media' => [ImageSourceType::Upload, ImageSourceType::Media],
                default => [ImageSourceType::from($sources)],
            };
        }

        $resolved = [];

        foreach ($sources as $source) {
            $type = $source instanceof ImageSourceType ? $source : ImageSourceType::from((string) $source);
            $resolved[$type->value] = $type;
        }

        throw_if($resolved === [], InvalidArgumentException::class, 'At least one image source type must be allowed.');

        return array_values($resolved);
    }

    /**
     * @param  list<ImageSourceType|string>|string|ImageSourceType|null  $sources
     * @return array<string, string>
     */
    public static function options(string|array|ImageSourceType|null $sources = null): array
    {
        return collect(self::resolve($sources))
            ->mapWithKeys(static fn (ImageSourceType $source): array => [$source->value => $source->getLabel()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function presetOptions(): array
    {
        return [
            'all' => __('capell::media.image_source_preset.all'),
            'url_only' => __('capell::media.image_source_preset.url_only'),
            'upload_only' => __('capell::media.image_source_preset.upload_only'),
            'media_only' => __('capell::media.image_source_preset.media_only'),
            'url_media' => __('capell::media.image_source_preset.url_media'),
            'upload_media' => __('capell::media.image_source_preset.upload_media'),
        ];
    }
}
