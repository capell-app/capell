<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Media;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        $disk = 'public';
        $fileName = $this->faker->uuid() . '.jpg';
        $mimeType = 'image/jpeg';
        $size = $this->faker->numberBetween(10000, 5000000);

        return [
            'model_type' => null,
            'model_id' => null,
            'collection_name' => MediaCollectionEnum::Image->value,
            'name' => $this->faker->word(),
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'disk' => $disk,
            'conversions_disk' => $disk,
            'size' => $size,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 0,
            'uuid' => (string) Str::uuid(),
        ];
    }

    public function fileType(string $type = 'jpg'): static
    {
        $type = $type === 'img' ? 'jpg' : $type;

        $mimeType = match ($type) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };

        return $this->state([
            'file_name' => $this->faker->uuid() . '.' . $type,
            'mime_type' => $mimeType,
        ]);
    }

    public function model(Model $relation): static
    {
        return $this->state([
            'model_type' => $relation->getMorphClass(),
            'model_id' => $relation->getKey(),
        ]);
    }

    public function collection(MediaCollectionEnum $collectionName): static
    {
        return $this->set('collection_name', $collectionName->value);
    }

    public function image(): static
    {
        return $this->collection(MediaCollectionEnum::Image);
    }

    public function video(): static
    {
        return $this->collection(MediaCollectionEnum::Video);
    }

    public function youtube(string $url = 'https://youtu.be/FgalLC99jzY'): static
    {
        $video = YouTubeVideoUrl::parse($url);
        $videoId = $video instanceof ExternalVideoData ? $video->videoId : $this->faker->uuid();

        return $this->state([
            'collection_name' => MediaCollectionEnum::Video->value,
            'file_name' => $videoId . '.youtube',
            'mime_type' => 'video/youtube',
            'size' => 0,
            'custom_properties' => [
                'capell' => [
                    'video' => $video?->toArray() ?? [],
                ],
            ],
        ]);
    }

    public function logo(): static
    {
        return $this->collection(MediaCollectionEnum::Logo);
    }

    public function logoInverted(): static
    {
        return $this->collection(MediaCollectionEnum::LogoInverted);
    }

    public function backgroundImage(): static
    {
        return $this->collection(MediaCollectionEnum::BackgroundImage);
    }
}
