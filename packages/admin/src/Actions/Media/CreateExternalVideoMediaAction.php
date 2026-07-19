<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Media;
use Capell\Core\Models\Site;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CreateExternalVideoMediaAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site, string $name, ExternalVideoData $video): Media
    {
        $media = new Media;
        $media->forceFill([
            'model_type' => $site->getMorphClass(),
            'model_id' => $site->getKey(),
            'uuid' => (string) Str::uuid(),
            'collection_name' => MediaCollectionEnum::Video->value,
            'name' => $name,
            'file_name' => $video->videoId . '.youtube',
            'mime_type' => 'video/youtube',
            'disk' => config('media-library.disk_name', 'public'),
            'conversions_disk' => config('media-library.disk_name', 'public'),
            'size' => 0,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => $this->nextVideoOrderForSite($site),
        ]);
        $media->setExternalVideo($video);
        $media->save();

        return $media;
    }

    private function nextVideoOrderForSite(Site $site): int
    {
        $max = Media::query()
            ->where('model_type', $site->getMorphClass())
            ->where('model_id', $site->getKey())
            ->where('collection_name', MediaCollectionEnum::Video->value)
            ->max('order_column');

        return (int) $max + 1;
    }
}
