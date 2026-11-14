<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\MediaConversionEnum;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Picks the public share image for a page: the page's social image, then its
 * featured (hero) image, then the site's default image. Reads only relations
 * the loaders already hydrated, so public rendering never queries.
 */
final class ResolveHeadImageUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(?object $page, ?object $site): ?string
    {
        $media = $this->loadedMedia($page, 'socialImage')
            ?? $this->loadedMedia($page, 'image')
            ?? $this->loadedMedia($site, 'image');

        if (! $media instanceof MediaContract) {
            return null;
        }

        $url = $media->getAvailableFullUrl([
            MediaConversionEnum::Large->value,
            MediaConversionEnum::Medium->value,
        ]);

        return $url !== '' ? $url : null;
    }

    private function loadedMedia(?object $model, string $relation): ?MediaContract
    {
        if (! $model instanceof Model || ! $model->relationLoaded($relation)) {
            return null;
        }

        $media = $model->getRelation($relation);

        return $media instanceof MediaContract ? $media : null;
    }
}
