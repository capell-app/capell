<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\MediaConversionEnum;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

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

        if (! $media instanceof MediaContract || ! $this->isPublic($media)) {
            return null;
        }

        $url = $media->getAvailableFullUrl([
            MediaConversionEnum::Large->value,
            MediaConversionEnum::Medium->value,
        ]);

        return $url !== '' && ! $this->isSignedUrl($url) ? $url : null;
    }

    /**
     * An allow-list: only an Eloquent media row on a disk configured as public (and a
     * public conversions disk, when it has one) is shared. A row on a private or unknown
     * disk never becomes a share image, and neither does a backend that does not record
     * public visibility on its rows.
     */
    private function isPublic(MediaContract $media): bool
    {
        if (! $media instanceof Model) {
            return false;
        }

        $attributes = $media->getAttributes();

        if (! $media instanceof SpatieMedia && ($attributes['visibility'] ?? null) !== 'public') {
            return false;
        }

        $disk = $attributes['disk'] ?? null;

        if (! is_string($disk) || ! $this->isPublicDisk($disk)) {
            return false;
        }

        $conversionsDisk = $attributes['conversions_disk'] ?? null;

        return $conversionsDisk === null || $conversionsDisk === '' || (is_string($conversionsDisk) && $this->isPublicDisk($conversionsDisk));
    }

    private function isPublicDisk(string $disk): bool
    {
        $config = config('filesystems.disks.' . $disk);

        return is_array($config) && ($config['visibility'] ?? null) === 'public';
    }

    private function isSignedUrl(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $parameters);

        return array_any(
            array_keys($parameters),
            static fn (int|string $parameter): bool => in_array(strtolower((string) $parameter), ['signature', 'expires', 'x-amz-signature', 'x-amz-expires', 'x-goog-signature', 'sig', 'se'], true),
        );
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
